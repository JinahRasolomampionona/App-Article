<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateArticleRequest;
use App\Jobs\AuditArticleJob;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use App\Services\QueueWorkerLauncher;
use App\Services\SiteContext;
use App\Services\WordPress\WordPressApiException;
use App\Services\WordPress\WordPressArticleService;
use App\Services\WordPress\WordPressSyncService;
use App\Support\HtmlContent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function __construct(
        protected SiteContext $context,
        protected AuditService $audit,
        protected WordPressArticleService $articles,
        protected WordPressSyncService $sync,
        protected QueueWorkerLauncher $worker,
    ) {}

    /**
     * Écran principal de gestion. Renvoie la page complète, ou seulement le
     * tableau lorsque l'appel vient d'un filtre AJAX.
     */
    public function index(Request $request): View|JsonResponse
    {
        $site = $this->resolveSite($request);

        if ($site === null) {
            return view('articles.index', [
                'site' => null,
                'articles' => null,
                'categories' => collect(),
                'filters' => $this->filters($request),
            ]);
        }

        $filters = $this->filters($request, $site);
        $articles = $this->query($request, $site);

        // « Aucun résultat » et « site jamais synchronisé » demandent deux
        // messages différents : le second ne se corrige pas en changeant les
        // filtres.
        $siteHasArticles = $articles->total() > 0 || $site->articles()->exists();

        if ($request->boolean('partial')) {
            return response()->json([
                'html' => view('articles.partials.rows', compact('articles', 'site', 'siteHasArticles'))->render(),
                'pagination' => view('articles.partials.pagination', compact('articles'))->render(),
                'meta' => [
                    'total' => $articles->total(),
                    'from' => $articles->firstItem(),
                    'to' => $articles->lastItem(),
                    'current_page' => $articles->currentPage(),
                    'last_page' => $articles->lastPage(),
                ],
            ]);
        }

        return view('articles.index', [
            'site' => $site,
            'articles' => $articles,
            'siteHasArticles' => $siteHasArticles,
            'categories' => $site->categories()->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function show(WordpressArticle $article): View
    {
        $this->authorize('view', $article);

        $article->load(['site', 'categories', 'issues' => fn ($query) => $query->orderByDesc('detected_at')]);

        return view('articles.show', [
            'article' => $article,
            'openIssues' => $article->issues->whereNull('resolved_at'),
            'resolvedIssues' => $article->issues->whereNotNull('resolved_at'),
        ]);
    }

    public function edit(WordpressArticle $article): View
    {
        $this->authorize('update', $article);

        $article->load(['site', 'categories']);
        $this->context->remember($article->site);

        return view('articles.edit', [
            'article' => $article,
            'site' => $article->site,
            'categories' => $article->site->categories()->orderBy('name')->get(),
            'selectedCategories' => $article->categories->pluck('id')->all(),
            'contentImages' => HtmlContent::make($article->content)->images(),
            'issues' => $article->openIssues()->get(),
            'settings' => AuditSettings::forUser(auth()->user()),
        ]);
    }

    /**
     * Enregistre les modifications vers WordPress, puis relance l'audit.
     */
    public function update(UpdateArticleRequest $request, WordpressArticle $article): JsonResponse
    {
        try {
            $result = $this->articles->update($article, $request->validated());
        } catch (WordPressApiException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], $e->status && $e->status < 500 ? $e->status : 502);
        }

        $article = $result['article']->refresh();

        // Audit immédiat sur les règles locales pour un retour instantané ;
        // les règles réseau (images) sont relancées en file d'attente.
        $this->audit->run(
            $article,
            AuditSettings::forUser($request->user()),
            allowNetwork: false,
            trigger: 'save',
        );

        AuditArticleJob::dispatch($article, 'save');
        $this->worker->ensureRunning();

        $article->refresh()->load('categories');

        return response()->json([
            'ok' => true,
            'message' => $result['changed'] === []
                ? 'Aucune modification à envoyer : l’article était déjà à jour.'
                : 'Article mis à jour sur WordPress.',
            'changed' => $result['changed'],
            'audit' => $this->auditPayload($article),
        ]);
    }

    /**
     * Relance un audit complet (règles réseau comprises) sur un article.
     */
    public function auditArticle(WordpressArticle $article): JsonResponse
    {
        $this->authorize('audit', $article);

        $this->audit->run(
            $article,
            AuditSettings::forUser(auth()->user()),
            allowNetwork: true,
            trigger: 'manual',
        );

        $article->refresh();

        return response()->json([
            'ok' => true,
            'message' => 'Audit terminé.',
            'audit' => $this->auditPayload($article),
            'row' => view('articles.partials.row', ['article' => $article->load(['categories', 'openIssues'])])->render(),
        ]);
    }

    /**
     * Statut posé à la main depuis le tableau.
     *
     * L'utilisateur qui a corrigé un article ailleurs — directement dans
     * WordPress, par exemple — doit pouvoir le déclarer sans attendre un
     * nouvel audit. Le moteur d'audit garde le dernier mot : le prochain
     * passage rouvrira les remarques encore présentes.
     */
    public function updateStatus(Request $request, WordpressArticle $article): JsonResponse
    {
        $this->authorize('update', $article);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(WordpressArticle::manualStatuses()))],
        ], [
            'status.in' => 'Statut inconnu.',
        ]);

        if (! $article->statusIsEditable()) {
            return response()->json([
                'ok' => false,
                'message' => 'Cet article ne présente aucun problème : son statut est déterminé par l’audit.',
            ], 422);
        }

        $article->applyManualStatus($validated['status']);
        $article->refresh();

        return response()->json([
            'ok' => true,
            'message' => $article->audit_status === WordpressArticle::AUDIT_FIXED
                ? 'Article marqué comme corrigé.'
                : 'Article marqué comme à corriger.',
            'row' => view('articles.partials.row', [
                'article' => $article->load(['categories', 'openIssues']),
            ])->render(),
        ]);
    }

    /**
     * Action en masse : programme l'audit des articles sélectionnés.
     */
    public function bulkAudit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $articles = WordpressArticle::query()
            ->whereIn('id', $validated['ids'])
            ->whereHas('site', fn ($query) => $query->where('user_id', $request->user()->id))
            ->get();

        foreach ($articles as $article) {
            AuditArticleJob::dispatch($article, 'bulk');
        }

        if ($articles->isNotEmpty()) {
            $this->worker->ensureRunning();
        }

        return response()->json([
            'ok' => true,
            'queued' => $articles->count(),
            'message' => $articles->count().' article(s) programmé(s) pour audit.',
        ]);
    }

    /**
     * Rafraîchit un article depuis WordPress (la source de vérité reste distante).
     */
    public function refresh(WordpressArticle $article): JsonResponse
    {
        $this->authorize('update', $article);

        try {
            $this->sync->syncArticle($article);
        } catch (WordPressApiException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Article rechargé depuis WordPress.',
        ]);
    }

    /**
     * Détail des problèmes, affiché dans un modal depuis le tableau.
     */
    public function issues(WordpressArticle $article): JsonResponse
    {
        $this->authorize('view', $article);

        return response()->json([
            'title' => $article->title,
            'edit_url' => route('articles.edit', $article),
            'issues' => $article->openIssues()->get()->map(fn ($issue) => [
                'type' => $issue->rule_type,
                'message' => $issue->message,
                'severity' => $issue->severity,
                'metadata' => $issue->metadata,
            ])->all(),
            'last_audited_at' => $article->last_audited_at?->diffForHumans(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<WordpressArticle>
     */
    protected function query(Request $request, WordpressSite $site): LengthAwarePaginator
    {
        $filters = $this->filters($request, $site);

        return $site->articles()
            // Chargement anticipé : évite N+1 sur les catégories et les remarques.
            ->with(['categories:id,name', 'openIssues:id,wordpress_article_id,rule_type,severity,message'])
            ->search($filters['search'])
            ->inCategories($filters['categories'], $filters['mode'])
            ->when($filters['status'] === 'needs_fix', fn ($query) => $query->where('audit_status', WordpressArticle::AUDIT_NEEDS_FIX))
            ->when($filters['status'] === 'ok', fn ($query) => $query->whereIn('audit_status', [WordpressArticle::AUDIT_OK, WordpressArticle::AUDIT_FIXED]))
            ->when($filters['status'] === 'pending', fn ($query) => $query->where('audit_status', WordpressArticle::AUDIT_PENDING))
            ->orderByDesc('wordpress_published_at')
            ->orderByDesc('wp_id')
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    /**
     * Filtres normalisés de la requête courante.
     *
     * Les identifiants de catégories sont restreints à ceux du site
     * sélectionné : une URL conservée après un changement de site — ou un lien
     * mis en favori — porterait sinon les catégories d'un autre site et le
     * tableau resterait vide sans raison visible.
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request, ?WordpressSite $site = null): array
    {
        $perPage = (int) $request->integer('per_page', (int) config('articleguard.sync.articles_per_page', 20));

        $categories = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $request->query('categories', [])
        ))));

        if ($categories !== [] && $site !== null) {
            $categories = $site->categories()
                ->whereIn('id', $categories)
                ->pluck('id')
                ->all();
        }

        return [
            'search' => trim((string) $request->query('search', '')),
            'categories' => $categories,
            'mode' => $request->query('mode') === 'all' ? 'all' : 'any',
            'status' => in_array($request->query('status'), ['needs_fix', 'ok', 'pending'], true)
                ? (string) $request->query('status')
                : 'all',
            'per_page' => in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 20,
        ];
    }

    protected function resolveSite(Request $request): ?WordpressSite
    {
        if ($request->filled('site')) {
            $site = $request->user()->sites()->find($request->integer('site'));

            if ($site) {
                $this->context->remember($site);

                return $site;
            }
        }

        return $this->context->current();
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditPayload(WordpressArticle $article): array
    {
        return [
            'status' => $article->audit_status,
            'status_label' => $article->statusLabel(),
            'status_variant' => $article->statusVariant(),
            'issues_count' => $article->issues_count,
            'last_audited_at' => $article->last_audited_at?->diffForHumans(),
            'issues' => $article->openIssues()->get()->map(fn ($issue) => [
                'type' => $issue->rule_type,
                'message' => $issue->message,
                'severity' => $issue->severity,
                'metadata' => $issue->metadata,
            ])->all(),
            'panel' => view('articles.partials.audit-panel', [
                'article' => $article,
                'issues' => $article->openIssues()->get(),
                'settings' => AuditSettings::forUser(auth()->user()),
            ])->render(),
        ];
    }
}
