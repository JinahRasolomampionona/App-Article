<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WordpressArticle;
use App\Services\Assignment\ArticleCompletionService;
use App\Services\Assignment\ArticleLockedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vérification des articles par l'Admin : réassigner un article à un agent
 * (typiquement un article « Corrigé » qui demande encore une modification) et
 * lui laisser un commentaire sur ce qu'il reste à faire.
 */
class ArticleReviewController extends Controller
{
    public function __construct(
        protected ArticleCompletionService $completion,
    ) {}

    public function reassign(Request $request, WordpressArticle $article): JsonResponse
    {
        $this->authorize('reassign', $article);

        $validated = $request->validate([
            'agent' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'agent.required' => 'Choisissez l’agent à qui réassigner l’article.',
            'agent.exists' => 'Agent inconnu ou désactivé.',
            'comment.max' => 'Le commentaire ne doit pas dépasser 2000 caractères.',
        ]);

        $agent = User::query()->findOrFail((int) $validated['agent']);

        // Seul un agent assigné au site peut en recevoir un article.
        if (! $article->site?->isAccessibleBy($agent)) {
            return response()->json([
                'ok' => false,
                'message' => $agent->name.' n’est pas assigné à ce site : impossible de lui réassigner cet article.',
            ], 422);
        }

        try {
            $article = $this->completion->reassign($article, $agent, $request->user(), $validated['comment'] ?? null);
        } catch (ArticleLockedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Article réassigné à '.$agent->name.'.',
            'row' => view('articles.partials.row', [
                'article' => $article->load(WordpressArticle::ROW_RELATIONS),
            ])->render(),
        ]);
    }

    public function storeNote(Request $request, WordpressArticle $article): JsonResponse
    {
        $this->authorize('comment', $article);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [
            'comment.required' => 'Écrivez le commentaire à transmettre à l’agent.',
            'comment.max' => 'Le commentaire ne doit pas dépasser 2000 caractères.',
        ]);

        // Destinataire : l'agent qui traite l'article, sinon celui qui l'a
        // déclaré corrigé.
        $agent = $article->isLocked() ? $article->assignee : $article->completer;

        $note = $this->completion->addNote($article, $request->user(), $agent, $validated['comment']);

        return response()->json([
            'ok' => true,
            'message' => $agent ? 'Commentaire transmis à '.$agent->name.'.' : 'Commentaire enregistré.',
            'note' => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $request->user()->name,
                'created_at' => $note->created_at?->translatedFormat('d/m/Y H:i'),
            ],
        ]);
    }
}
