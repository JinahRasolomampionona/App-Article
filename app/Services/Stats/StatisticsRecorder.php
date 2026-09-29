<?php

namespace App\Services\Stats;

use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Historique des corrections.
 *
 * Une entrée est créée lorsqu'un agent déclare un article « Corrigé » : elle
 * porte son nom et la liste des erreurs qu'il a corrigées. L'audit, lui, ne
 * crédite personne — il se contente de constater l'état de l'article.
 */
class StatisticsRecorder
{
    /** Statuts qui valent correction lors de la reprise initiale. */
    protected const RECORDED = [WordpressArticle::AUDIT_OK, WordpressArticle::AUDIT_FIXED];

    /**
     * Inscrit la fin de traitement d'un article (« Corrigé » déclaré par
     * l'agent) avec la liste des erreurs corrigées : c'est ce que l'Admin
     * relit avant de valider ou de réassigner.
     *
     * @param  array<int, array{type: string, message: string, how: string}>  $issues
     */
    public function recordCompletion(WordpressArticle $article, User $agent, array $issues): ?ArticleStatusHistory
    {
        $site = $article->site;

        if ($site === null) {
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
            'status' => WordpressArticle::AUDIT_FIXED,
            'resolved_manually' => true,
            'agent' => $agent->name,
            'agent_user_id' => $agent->id,
            'issues_resolved' => count($issues),
            'resolved_issues' => $issues,
            'recorded_at' => now(),
        ]);
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
