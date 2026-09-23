<?php

namespace App\Services\Stats;

use App\Models\ArticleStatusHistory;
use App\Models\WordpressArticle;
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
 */
class StatisticsRecorder
{
    /** Statuts qui valent correction, et donc entrée d'historique. */
    protected const RECORDED = [WordpressArticle::AUDIT_OK, WordpressArticle::AUDIT_FIXED];

    public function record(
        WordpressArticle $article,
        ?string $previousStatus,
        string $status,
        bool $manual = false,
        int $issuesResolved = 0,
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
            // Agent assigné au moment de la correction : l'historique doit
            // rester juste même si l'article est réassigné ensuite.
            'agent' => $article->agent,
            'issues_resolved' => $issuesResolved,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Réattribue à un agent la conformité déjà acquise d'un article.
     *
     * Assigner quelqu'un à un article déjà « OK » ou « Corrigé » doit le faire
     * apparaître dans ses statistiques : sans cela, seuls les articles
     * corrigés *après* l'assignation lui seraient comptés.
     *
     * C'est bien une réattribution, pas une nouvelle correction : l'entrée
     * existante change de titulaire plutôt que d'être doublée — l'article n'a
     * été corrigé qu'une fois, et le total général ne doit pas bouger. Seule
     * la dernière entrée est touchée : une correction antérieure faite par
     * quelqu'un d'autre lui reste acquise.
     *
     * Un article encore « à corriger » ne donne lieu à rien : il n'y a pas
     * encore de correction à attribuer, et `record()` posera l'agent le moment
     * venu.
     */
    public function reassign(WordpressArticle $article, ?string $agent): ?ArticleStatusHistory
    {
        if (! in_array($article->audit_status, self::RECORDED, true)) {
            return null;
        }

        $entry = ArticleStatusHistory::query()
            ->where('wordpress_article_id', $article->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        if ($entry === null) {
            // Article conforme jamais inscrit — historique incomplet : on le
            // rattrape en datant l'entrée de sa dernière analyse.
            return $this->recordExisting($article, $agent);
        }

        $entry->forceFill(['agent' => $agent])->save();

        return $entry;
    }

    /**
     * Crée l'entrée manquante d'un article déjà conforme, datée de ce que l'on
     * sait de sa correction plutôt que de l'instant présent.
     */
    protected function recordExisting(WordpressArticle $article, ?string $agent): ?ArticleStatusHistory
    {
        $site = $article->site;

        if ($site === null) {
            return null;
        }

        return ArticleStatusHistory::create([
            'user_id' => $site->user_id,
            'wordpress_site_id' => $site->id,
            'site_name' => $site->name,
            'site_url' => $site->url,
            'wordpress_article_id' => $article->id,
            'wp_id' => $article->wp_id,
            'article_title' => $article->title,
            'article_url' => $article->link,
            'status' => $article->audit_status,
            'resolved_manually' => $article->status_set_manually_at !== null
                && $article->audit_status === WordpressArticle::AUDIT_FIXED,
            'agent' => $agent,
            'issues_resolved' => 0,
            'recorded_at' => $article->issues_resolved_at
                ?? $article->last_audited_at
                ?? now(),
        ]);
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
                'a.agent',
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
                        'agent' => $article->agent,
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
