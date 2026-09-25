<?php

namespace App\Services\Assignment;

use App\Models\ArticleAssignment;
use App\Models\User;
use App\Models\WordpressArticle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Prise en charge des articles : un seul agent actif par article.
 *
 * Le contrôle est fait en base, jamais côté navigateur. Chaque opération
 * s'exécute dans une transaction qui verrouille la ligne (`SELECT … FOR
 * UPDATE`), puis écrit avec une condition sur le détenteur attendu : si deux
 * agents cliquent au même instant, la seconde écriture ne trouve plus de ligne
 * correspondante et échoue — le premier arrivé garde l'article.
 *
 * Le verrou expire (`lock_expires_at`) : un agent qui ferme son navigateur ne
 * bloque pas l'article. L'éditeur le prolonge par un heartbeat.
 */
class ArticleLockService
{
    /** Durée d'un verrou, prolongée à chaque heartbeat. */
    public function ttlMinutes(): int
    {
        return max(1, (int) config('articleguard.locks.ttl_minutes', 30));
    }

    /**
     * Donne l'article à `$agent`.
     *
     * Un agent ne peut prendre qu'un article disponible (ou déjà à lui). Un
     * Admin (`$by`) peut l'attribuer à n'importe quel agent, y compris en le
     * retirant à celui qui le détient.
     *
     * @throws ArticleLockedException
     */
    public function take(WordpressArticle $article, User $agent, ?User $by = null): WordpressArticle
    {
        $by ??= $agent;
        $forcing = $by->isAdmin();

        return DB::transaction(function () use ($article, $agent, $by, $forcing) {
            $current = $this->lockRow($article);
            $now = now();

            $previousHolder = $current->assigned_to;
            $wasLocked = $current->isLocked();

            if ($wasLocked && $previousHolder !== $agent->id && ! $forcing) {
                throw ArticleLockedException::heldBy($current->activeAgentName());
            }

            // Écriture conditionnelle : ne réussit que si la ligne est encore
            // dans l'état lu ci-dessus.
            $updated = WordpressArticle::query()
                ->whereKey($current->id)
                ->where(function ($query) use ($previousHolder) {
                    $previousHolder === null
                        ? $query->whereNull('assigned_to')
                        : $query->where('assigned_to', $previousHolder);
                })
                ->update([
                    'assigned_to' => $agent->id,
                    'locked_at' => $wasLocked && $previousHolder === $agent->id ? $current->locked_at : $now,
                    'lock_expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
                ]);

            if ($updated !== 1) {
                $fresh = $current->fresh('assignee');

                throw ArticleLockedException::heldBy($fresh?->activeAgentName());
            }

            if ($previousHolder === $agent->id && $wasLocked) {
                // Simple prolongation : même prise en charge.
                return $current->fresh('assignee');
            }

            if ($previousHolder !== null) {
                $this->closeOpenAssignments(
                    $current,
                    $wasLocked ? ArticleAssignment::REASON_REASSIGNED : ArticleAssignment::REASON_EXPIRED,
                    $wasLocked ? $now : ($current->lock_expires_at ?? $now),
                );
            }

            ArticleAssignment::create([
                'wordpress_article_id' => $current->id,
                'wordpress_site_id' => $current->wordpress_site_id,
                'user_id' => $agent->id,
                'agent_name' => $agent->name,
                'assigned_by' => $by->id !== $agent->id ? $by->id : null,
                'taken_at' => $now,
            ]);

            Log::info('Article pris en charge.', [
                'article_id' => $current->id,
                'agent_id' => $agent->id,
                'by' => $by->id,
                'previous_agent_id' => $wasLocked ? $previousHolder : null,
            ]);

            return $current->fresh('assignee');
        });
    }

    /**
     * Rend l'article disponible.
     *
     * Un agent ne libère que son propre article ; un Admin peut libérer
     * n'importe lequel.
     *
     * @throws ArticleLockedException
     */
    public function release(WordpressArticle $article, User $actor, string $reason = ArticleAssignment::REASON_RELEASED): WordpressArticle
    {
        return DB::transaction(function () use ($article, $actor, $reason) {
            $current = $this->lockRow($article);

            if ($current->assigned_to === null) {
                return $current;
            }

            if (! $actor->isAdmin() && $current->assigned_to !== $actor->id) {
                if ($current->isLocked()) {
                    throw ArticleLockedException::heldBy($current->activeAgentName());
                }

                // Verrou expiré d'un autre agent : l'article est déjà
                // disponible, rien à libérer.
                return $current;
            }

            $this->clearLock($current, $current->assigned_to);
            $this->closeOpenAssignments(
                $current,
                $current->isLocked() ? $reason : ArticleAssignment::REASON_EXPIRED,
                $current->isLocked() ? now() : ($current->lock_expires_at ?? now()),
            );

            Log::info('Article libéré.', [
                'article_id' => $current->id,
                'agent_id' => $current->assigned_to,
                'by' => $actor->id,
            ]);

            return $current->fresh();
        });
    }

    /**
     * Prolonge le verrou pendant que l'agent travaille dans l'éditeur.
     *
     * @throws ArticleLockedException si l'utilisateur ne détient plus l'article
     */
    public function heartbeat(WordpressArticle $article, User $user): Carbon
    {
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        $updated = WordpressArticle::query()
            ->whereKey($article->id)
            ->where('assigned_to', $user->id)
            ->where('lock_expires_at', '>', now())
            ->update(['lock_expires_at' => $expiresAt]);

        if ($updated !== 1) {
            throw ArticleLockedException::notHeld();
        }

        return $expiresAt;
    }

    /**
     * Vérifie, au moment d'écrire, que l'utilisateur détient bien l'article.
     *
     * @throws ArticleLockedException
     */
    public function assertHeldBy(WordpressArticle $article, User $user): void
    {
        $fresh = $article->fresh('assignee') ?? $article;

        if ($fresh->isLockedBy($user)) {
            return;
        }

        throw $fresh->isLocked()
            ? ArticleLockedException::heldBy($fresh->activeAgentName())
            : ArticleLockedException::notHeld();
    }

    /**
     * Clôt la prise en charge après correction : résultat d'audit enregistré,
     * article rendu disponible.
     *
     * @throws ArticleLockedException
     */
    public function complete(WordpressArticle $article, User $user): ArticleAssignment
    {
        return DB::transaction(function () use ($article, $user) {
            $current = $this->lockRow($article);

            if (! $current->isLockedBy($user)) {
                throw $current->isLocked()
                    ? ArticleLockedException::heldBy($current->activeAgentName())
                    : ArticleLockedException::notHeld();
            }

            $now = now();

            $assignment = $this->openAssignment($current, $user->id) ?? ArticleAssignment::create([
                'wordpress_article_id' => $current->id,
                'wordpress_site_id' => $current->wordpress_site_id,
                'user_id' => $user->id,
                'agent_name' => $user->name,
                'taken_at' => $current->locked_at ?? $now,
            ]);

            $assignment->forceFill([
                'released_at' => $now,
                'release_reason' => ArticleAssignment::REASON_COMPLETED,
                'completed_at' => $now,
                'audit_result' => $current->audit_status,
                'issues_remaining' => $current->issues_count,
            ])->save();

            $this->clearLock($current, $user->id);

            Log::info('Correction terminée.', [
                'article_id' => $current->id,
                'agent_id' => $user->id,
                'audit_status' => $current->audit_status,
                'issues_remaining' => $current->issues_count,
            ]);

            return $assignment;
        });
    }

    /**
     * Nettoie les verrous expirés et clôt les prises en charge
     * correspondantes. La disponibilité n'en dépend pas (un verrou expiré est
     * déjà ignoré partout) : il s'agit de garder un historique exact.
     */
    public function releaseExpired(): int
    {
        $released = 0;

        WordpressArticle::query()
            ->whereNotNull('assigned_to')
            ->where('lock_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($articles) use (&$released) {
                foreach ($articles as $article) {
                    DB::transaction(function () use ($article, &$released) {
                        $current = $this->lockRow($article);

                        if ($current->assigned_to === null || $current->isLocked()) {
                            return;
                        }

                        $this->closeOpenAssignments(
                            $current,
                            ArticleAssignment::REASON_EXPIRED,
                            $current->lock_expires_at ?? now(),
                        );
                        $this->clearLock($current, $current->assigned_to);
                        $released++;
                    });
                }
            });

        return $released;
    }

    /**
     * Agent à qui attribuer une correction : le détenteur actuel, sinon le
     * dernier agent ayant travaillé sur l'article (l'audit réseau peut
     * confirmer la correction après la libération).
     */
    public function responsibleAgent(WordpressArticle $article): ?User
    {
        if ($article->isLocked()) {
            return $article->assignee;
        }

        $last = ArticleAssignment::query()
            ->where('wordpress_article_id', $article->id)
            ->whereNotNull('user_id')
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->first();

        // Une prise en charge ancienne ne vaut pas attribution : seule une
        // correction proche du travail de l'agent lui revient.
        if ($last === null || ($last->released_at && $last->released_at->lt(now()->subDay()))) {
            return null;
        }

        return $last->user;
    }

    protected function lockRow(WordpressArticle $article): WordpressArticle
    {
        return WordpressArticle::query()
            ->with('assignee:id,name')
            ->whereKey($article->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function clearLock(WordpressArticle $article, int $holder): void
    {
        WordpressArticle::query()
            ->whereKey($article->id)
            ->where('assigned_to', $holder)
            ->update([
                'assigned_to' => null,
                'locked_at' => null,
                'lock_expires_at' => null,
            ]);
    }

    protected function openAssignment(WordpressArticle $article, int $userId): ?ArticleAssignment
    {
        return ArticleAssignment::query()
            ->where('wordpress_article_id', $article->id)
            ->where('user_id', $userId)
            ->whereNull('released_at')
            ->latest('taken_at')
            ->first();
    }

    protected function closeOpenAssignments(WordpressArticle $article, string $reason, Carbon $at): void
    {
        ArticleAssignment::query()
            ->where('wordpress_article_id', $article->id)
            ->whereNull('released_at')
            ->update([
                'released_at' => $at,
                'release_reason' => $reason,
                'updated_at' => now(),
            ]);
    }
}
