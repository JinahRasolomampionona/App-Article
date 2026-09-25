<?php

namespace App\Services\Stats;

use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Services\Assignment\ArticleLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inscrit dans l'historique un article qui vient d'atteindre « OK » ou
 * « Corrigé ».
 *
 * Seul un *changement* de statut est enregistré : réauditer dix fois un
 * article déjà conforme ne doit pas gonfler les statistiques. La détection a
 * donc besoin du statut précédent, que les appelants fournissent avant
 * d'écrire le nouveau.
 *
 * La correction est attribuée à l'agent qui détient l'article, ou à défaut au
 * dernier agent qui l'a traité : l'audit réseau, en file d'attente, peut
 * confirmer la correction quelques instants après la libération.
 */
class StatisticsRecorder
{
    /** Statuts qui valent correction, et donc entrée d'historique. */
    protected const RECORDED = [WordpressArticle::AUDIT_OK, WordpressArticle::AUDIT_FIXED];

    public function __construct(
        protected ArticleLockService $locks,
    ) {}

    public function record(
        WordpressArticle $article,
        ?string $previousStatus,
        string $status,
        bool $manual = false,
        int $issuesResolved = 0,
        ?User $agent = null,
    ): ?ArticleStatusHistory {
        if (! in_array($status, self::RECORDED, true) || $status === $previousStatus) {
            return null;
        }

        $site = $article->site;

        if ($site === null) {
            // Article orphelin : sans site, l'entrée serait inexploitable.
            Log::warning('Statistiques : article sans site, historique ignoré.', [
                'article_id' => $article->id,
            ]);

            return null;
        }

        // Agent au moment de la correction : celui qui la déclare, sinon celui
        // qui traite l'article. L'historique reste juste même si l'article est
        // repris ensuite par quelqu'un d'autre.
        $agent ??= $this->locks->responsibleAgent($article);

        return ArticleStatusHistory::create([
            'user_id' => $site->user_id,
            'wordpress_site_id' => $site->id,
            'site_name' => $site->name,
            'site_url' => $site->url,
            'wordpress_article_id' => $article->id,
            'wp_id' => $article->wp_id,
            'article_title' => $article->title,
            'article_url' => $article->link,
            'status' => $status,
            'resolved_manually' => $manual,
            'agent' => $agent?->name,
            'agent_user_id' => $agent?->id,
            'issues_resolved' => $issuesResolved,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Annule la dernière correction déclarée à la main d'un article qui repasse
     * « À corriger » : une déclaration retirée ne doit plus compter dans les
     * statistiques. Une correction confirmée par un audit n'est jamais retirée.
     */
    public function retractManualCorrection(WordpressArticle $article): bool
    {
        $last = ArticleStatusHistory::query()
            ->where('wordpress_article_id', $article->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        if ($last === null || ! $last->resolved_manually || $last->status !== WordpressArticle::AUDIT_FIXED) {
            return false;
        }

        return (bool) $last->delete();
    }

    /**
     * Rattache au compte d'un agent les entrées d'historique qui ne portent
     * que son nom — saisies avant que les agents n'aient leur compte.
     */
    public function linkAgentHistory(User $agent): int
    {
        return ArticleStatusHistory::query()
            ->whereNull('agent_user_id')
            ->whereRaw('lower(agent) = ?', [mb_strtolower(trim((string) $agent->name))])
            ->update(['agent_user_id' => $agent->id]);
    }

    /**
     * Inscrit dans l'historique les articles déjà conformes qui n'y figurent
     * pas encore.
     *
     * Sert à la reprise initiale — un site audité avant la mise en place de
     * l'historique n'apparaîtrait sinon qu'au prochain changement de statut —
     * et reste rejouable : les articles déjà présents sont ignorés.
     *
     * @return int nombre d'entrées créées
     */
    public function backfill(): int
    {
        $created = 0;

        DB::table('wordpress_articles as a')
            ->join('wordpress_sites as s', 's.id', '=', 'a.wordpress_site_id')
            ->whereIn('a.audit_status', self::RECORDED)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('article_status_history as h')
                    ->whereColumn('h.wordpress_article_id', 'a.id');
            })
            ->select([
                's.user_id',
                's.id as site_id',
                's.name as site_name',
                's.url as site_url',
                'a.id as article_id',
                'a.wp_id',
                'a.title',
                'a.link',
                'a.audit_status',
                'a.issues_resolved_at',
                'a.status_set_manually_at',
                'a.last_audited_at',
                'a.updated_at',
            ])
            ->orderBy('a.id')
            ->chunk(500, function ($articles) use (&$created) {
                $rows = [];

                foreach ($articles as $article) {
                    $rows[] = [
                        'user_id' => $article->user_id,
                        'wordpress_site_id' => $article->site_id,
                        'site_name' => $article->site_name,
                        'site_url' => $article->site_url,
                        'wordpress_article_id' => $article->article_id,
                        'wp_id' => $article->wp_id,
                        'article_title' => $article->title,
                        'article_url' => $article->link,
                        'status' => $article->audit_status,
                        // Un statut posé à la main ne vaut que pour « Corrigé ».
                        'resolved_manually' => $article->status_set_manually_at !== null
                            && $article->audit_status === WordpressArticle::AUDIT_FIXED,
                        'agent' => null,
                        'issues_resolved' => 0,
                        // À défaut de date de correction, la dernière analyse
                        // reste le repère le plus proche de la réalité.
                        'recorded_at' => $article->issues_resolved_at
                            ?? $article->last_audited_at
                            ?? $article->updated_at
                            ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('article_status_history')->insert($rows);
                    $created += count($rows);
                }
            });

        return $created;
    }
}
