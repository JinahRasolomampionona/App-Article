<?php

namespace App\Http\Controllers;

use App\Jobs\AuditArticleJob;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Services\Assignment\ArticleLockedException;
use App\Services\Assignment\ArticleLockService;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use App\Services\QueueWorkerLauncher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Prise en charge des articles : prendre, libérer, attribuer, prolonger,
 * terminer — et l'état des assignations pour le rafraîchissement du tableau.
 *
 * Chaque action est autorisée par WordpressArticlePolicy puis exécutée par
 * ArticleLockService, qui tranche en base : le navigateur n'est jamais cru
 * sur parole.
 */
class ArticleLockController extends Controller
{
    public function __construct(
        protected ArticleLockService $locks,
    ) {}

    /**
     * Prendre l'article pour soi.
     */
    public function take(Request $request, WordpressArticle $article): JsonResponse
    {
        $this->authorize('take', $article);

        return $this->attempt($request, $article, function () use ($request, $article) {
            $article = $this->locks->take($article, $request->user());

            return [
                'message' => 'Article pris en charge. Vous seul pouvez le modifier.',
                'edit_url' => route('articles.edit', $article),
            ];
        });
    }

    /**
     * Libérer l'article : il redevient disponible pour les autres agents.
     */
    public function release(Request $request, WordpressArticle $article): JsonResponse
    {
        $this->authorize('release', $article);

        return $this->attempt($request, $article, function () use ($request, $article) {
            $this->locks->release($article, $request->user());

            return ['message' => 'Article libéré : il est de nouveau disponible.'];
        });
    }

    /**
     * Liste déroulante « Agent » du tableau.
     *
     * - vide : libérer ;
     * - soi-même : prendre ;
     * - un autre compte : attribuer (Admin uniquement).
     */
    public function assign(Request $request, WordpressArticle $article): JsonResponse
    {
        $validated = $request->validate([
            'agent' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('is_active', true),
            ],
        ], [
            'agent.exists' => 'Agent inconnu ou désactivé.',
            'agent.integer' => 'Agent inconnu.',
        ], ['agent' => 'agent']);

        $user = $request->user();
        $agentId = $validated['agent'] !== null ? (int) $validated['agent'] : null;

        if ($agentId === null) {
            return $this->release($request, $article);
        }

        if ($agentId === $user->id) {
            return $this->take($request, $article);
        }

        $this->authorize('assign', $article);

        $agent = User::query()->findOrFail($agentId);

        // L'agent travaille avec ses propres identifiants : il doit avoir
        // connecté ce site pour pouvoir en recevoir un article.
        if (! $article->site?->isAccessibleBy($agent)) {
            return response()->json([
                'ok' => false,
                'message' => $agent->name.' n’a pas connecté ce site : impossible de lui attribuer cet article.',
            ], 422);
        }

        return $this->attempt($request, $article, function () use ($article, $agent, $user) {
            $this->locks->take($article, $agent, by: $user);

            return ['message' => 'Article attribué à '.$agent->name.'.'];
        });
    }

    /**
     * Prolonge le verrou pendant que l'agent travaille dans l'éditeur.
     */
    public function heartbeat(Request $request, WordpressArticle $article): JsonResponse
    {
        try {
            $expiresAt = $this->locks->heartbeat($article, $request->user());
        } catch (ArticleLockedException $e) {
            $fresh = $article->fresh('assignee');

            return response()->json([
                'ok' => false,
                'lost' => true,
                'state' => $fresh?->lockStateFor($request->user()) ?? 'available',
                'agent' => $fresh?->activeAgentName(),
                'message' => $fresh?->isLocked()
                    ? 'Cet article est maintenant traité par '.$fresh->activeAgentName().'. Vos modifications ne peuvent plus être enregistrées.'
                    : $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Fin de correction : audit complet, activité enregistrée, article libéré.
     *
     * Les modifications ont déjà été envoyées à WordPress par « Mettre à
     * jour » (l'éditeur enregistre d'abord ce qui ne l'est pas). Le statut
     * « Corrigé » n'est jamais déclaré : il découle de ce nouvel audit.
     */
    public function finish(Request $request, WordpressArticle $article, AuditService $audit, QueueWorkerLauncher $worker): JsonResponse
    {
        $this->authorize('update', $article);

        @set_time_limit(180);

        $audit->run(
            $article,
            AuditSettings::forUser($article->site?->user),
            allowNetwork: true,
            trigger: 'finish',
        );

        $article->refresh();

        try {
            $assignment = $this->locks->complete($article, $request->user());
        } catch (ArticleLockedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
        }

        $fixed = $article->issues_count === 0;

        return response()->json([
            'ok' => true,
            'status' => $article->audit_status,
            'status_label' => $article->statusLabel(),
            'issues_count' => $article->issues_count,
            'completed_at' => $assignment->completed_at?->toIso8601String(),
            'message' => $fixed
                ? 'Correction terminée : aucun problème détecté. L’article est libéré.'
                : 'Correction terminée, mais '.$article->issues_count.' problème(s) restent à corriger. L’article est libéré.',
            'redirect' => route('articles.index', ['site' => $article->wordpress_site_id]),
        ]);
    }

    /**
     * État des assignations des lignes affichées, pour le rafraîchissement
     * périodique du tableau : seules les cellules « Agent » et « Actions »
     * sont renvoyées, jamais la liste des articles.
     */
    public function poll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $user = $request->user();

        $articles = WordpressArticle::query()
            ->whereIn('id', $validated['ids'])
            ->whereHas('site', fn ($query) => $query->accessibleBy($user))
            ->with('assignee:id,name')
            ->get(['id', 'title', 'wordpress_site_id', 'audit_status', 'assigned_to', 'locked_at', 'lock_expires_at']);

        return response()->json([
            'ok' => true,
            'articles' => $articles->mapWithKeys(fn (WordpressArticle $article) => [
                $article->id => [
                    'state' => $article->lockStateFor($user),
                    'agent_id' => $article->activeAgentId(),
                    'agent' => $article->activeAgentName(),
                    // Empreinte : le navigateur ne remplace la cellule que si
                    // l'état a changé, pour ne pas fermer une liste ouverte.
                    'key' => $article->lockStateFor($user).':'.($article->activeAgentId() ?? 0),
                    'agent_html' => view('articles.partials.agent-cell', ['article' => $article])->render(),
                    'actions_html' => view('articles.partials.actions-cell', ['article' => $article])->render(),
                ],
            ]),
        ]);
    }

    /**
     * Exécute une action de verrou et renvoie l'état à jour de la ligne, ou
     * un 409 lisible si un autre agent a été plus rapide.
     *
     * @param  callable(): array<string, mixed>  $action
     */
    protected function attempt(Request $request, WordpressArticle $article, callable $action): JsonResponse
    {
        try {
            $payload = $action();
        } catch (ArticleLockedException $e) {
            return response()->json(
                ['ok' => false, 'message' => $e->getMessage(), 'agent' => $e->holder] + $this->state($request, $article),
                409,
            );
        }

        return response()->json(['ok' => true] + $payload + $this->state($request, $article));
    }

    /**
     * @return array<string, mixed>
     */
    protected function state(Request $request, WordpressArticle $article): array
    {
        $fresh = $article->fresh(['assignee:id,name']) ?? $article;
        $user = $request->user();

        return [
            'state' => $fresh->lockStateFor($user),
            'agent_id' => $fresh->activeAgentId(),
            'agent' => $fresh->activeAgentName(),
            'key' => $fresh->lockStateFor($user).':'.($fresh->activeAgentId() ?? 0),
            'agent_html' => view('articles.partials.agent-cell', ['article' => $fresh])->render(),
            'actions_html' => view('articles.partials.actions-cell', ['article' => $fresh])->render(),
            // Ligne complète : le statut manuel n'est modifiable que par le
            // détenteur, il change donc avec la prise en charge.
            'row' => view('articles.partials.row', [
                'article' => $fresh->load(['categories:id,name', 'openIssues']),
            ])->render(),
        ];
    }
}
