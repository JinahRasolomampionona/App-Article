<?php

namespace App\Services\Assignment;

use App\Models\ArticleAssignment;
use App\Models\ArticleAuditIssue;
use App\Models\ArticleNote;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Services\Stats\StatisticsRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fin de traitement d'un article (« Corrigé ») et réassignation par l'Admin.
 *
 * « Corrigé » est posé à la main par l'agent qui détient l'article : il a
 * corrigé les problèmes (« À corriger ») ou vérifié l'article (« À vérifier »).
 * L'article quitte alors la liste des agents, garde la trace des erreurs
 * corrigées, et attend la vérification de l'Admin — qui peut le réassigner,
 * avec un commentaire, s'il reste une modification à faire.
 */
class ArticleCompletionService
{
    public function __construct(
        protected ArticleLockService $locks,
        protected StatisticsRecorder $recorder,
    ) {}

    /**
     * Déclare l'article corrigé au nom de son détenteur actuel.
     *
     * @throws ArticleLockedException si personne ne détient l'article
     */
    public function complete(WordpressArticle $article, User $by): WordpressArticle
    {
        return DB::transaction(function () use ($article, $by) {
            $current = WordpressArticle::query()
                ->with(['assignee:id,name', 'site'])
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($current->isCompleted()) {
                return $current;
            }

            // L'agent crédité est celui qui détient l'article — l'Admin peut
            // déclarer à sa place, jamais un article que personne n'a pris.
            $agent = $current->isLocked() ? $current->assignee : null;

            if ($agent === null) {
                throw ArticleLockedException::unassigned();
            }

            if (! $by->isAdmin() && $agent->id !== $by->id) {
                throw ArticleLockedException::heldBy($agent->name);
            }

            $corrected = $this->correctedIssues($current);

            // Remarques encore ouvertes : l'agent déclare les avoir traitées.
            $current->openIssues()->update([
                'resolved_at' => now(),
                'resolved_manually' => true,
            ]);

            $current->forceFill([
                'audit_status' => $current->audit_status === WordpressArticle::AUDIT_NEEDS_FIX
                    ? WordpressArticle::AUDIT_FIXED
                    : $current->audit_status,
                'issues_count' => 0,
                'issues_resolved_at' => $corrected !== [] ? now() : $current->issues_resolved_at,
                'status_set_manually_at' => now(),
                'completed_at' => now(),
                'completed_by' => $agent->id,
            ])->save();

            $this->recorder->recordCompletion($current, $agent, $corrected);
            $this->locks->complete($current, $agent);

            Log::info('Article déclaré corrigé.', [
                'article_id' => $current->id,
                'agent_id' => $agent->id,
                'by' => $by->id,
                'issues' => count($corrected),
            ]);

            return $current->fresh(['assignee:id,name', 'completer:id,name']);
        });
    }

    /**
     * Admin : rend l'article à un agent (corrigé ou non), avec un commentaire
     * facultatif sur ce qu'il reste à faire.
     */
    public function reassign(WordpressArticle $article, User $agent, User $admin, ?string $comment = null): WordpressArticle
    {
        return DB::transaction(function () use ($article, $agent, $admin, $comment) {
            // La prise en charge efface la fin de traitement : l'article
            // réapparaît dans la liste de l'agent.
            $article = $this->locks->take($article, $agent, by: $admin);

            if (filled($comment)) {
                $this->addNote($article, $admin, $agent, $comment);
            }

            Log::info('Article réassigné.', [
                'article_id' => $article->id,
                'agent_id' => $agent->id,
                'by' => $admin->id,
            ]);

            return $article->fresh(['assignee:id,name']);
        });
    }

    public function addNote(WordpressArticle $article, User $author, ?User $agent, string $body): ArticleNote
    {
        return ArticleNote::create([
            'wordpress_article_id' => $article->id,
            'author_id' => $author->id,
            'agent_id' => $agent?->id,
            'body' => trim($body),
        ]);
    }

    /**
     * Erreurs traitées pendant la prise en charge : celles encore ouvertes
     * (l'agent les déclare corrigées) et celles qu'un audit a vues disparaître
     * depuis qu'il a pris l'article.
     *
     * @return array<int, array{type: string, message: string, how: string}>
     */
    protected function correctedIssues(WordpressArticle $article): array
    {
        $since = ArticleAssignment::query()
            ->where('wordpress_article_id', $article->id)
            ->whereNull('released_at')
            ->min('taken_at') ?? $article->locked_at;

        return ArticleAuditIssue::query()
            ->where('wordpress_article_id', $article->id)
            ->where(function ($query) use ($since) {
                $query->whereNull('resolved_at');

                if ($since !== null) {
                    $query->orWhere('resolved_at', '>=', $since);
                }
            })
            ->orderBy('detected_at')
            ->get(['rule_type', 'message', 'resolved_at', 'resolved_manually'])
            ->map(fn (ArticleAuditIssue $issue) => [
                'type' => $issue->rule_type,
                'message' => $issue->message,
                // `audit` : correction confirmée par un audit ; `manual` :
                // déclarée par l'agent.
                'how' => $issue->resolved_at !== null && ! $issue->resolved_manually ? 'audit' : 'manual',
            ])
            ->values()
            ->all();
    }
}
