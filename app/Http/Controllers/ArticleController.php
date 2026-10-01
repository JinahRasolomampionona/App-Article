<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateArticleRequest;
use App\Jobs\AuditArticleJob;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\Assignment\ArticleCompletionService;
use App\Services\Assignment\ArticleLockedException;
use App\Services\Audit\ArticleImageReport;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use App\Services\QueueWorkerLauncher;
use App\Services\SiteContext;
use App\Services\WordPress\ArticleSaveService;
use App\Services\WordPress\WordPressApiException;
use App\Services\WordPress\WordPressArticleService;
use App\Services\WordPress\WordPressSyncService;
use App\Support\AgentCatalog;
use App\Support\HtmlContent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                'categoryCounts' => [],
                'filters' => $this->filters($request),
                'agents' => AgentCatalog::all(),
                'pollSeconds' => (int) config('articleguard.locks.poll_seconds', 8),
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
                // Les compteurs de catégories suivent les filtres actifs : le
                // nombre affiché doit annoncer ce que donnerait la sélection.
                'category_counts' => $this->categoryCounts($site, $filters),
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
            'categoryCounts' => $this->categoryCounts($site, $filters),
            'filters' => $filters,
            'agents' => AgentCatalog::all(),
            'pollSeconds' => (int) config('articleguard.locks.poll_seconds', 8),
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

    /**
     * Éditeur. Ouvert à tous en consultation ; modifiable seulement par
     * l'agent qui détient l'article — les écritures sont de toute façon
     * refusées côté serveur à quiconque ne détient pas le verrou.
     */
    public function edit(Request $request, WordpressArticle $article): View
    {
        $this->authorize('view', $article);

        $article->load(['site', 'categories', 'assignee:id,name', 'notes.author:id,name']);
        $user = $request->user();
        $this->context->remember($article->site);

        return view('articles.edit', [
            'article' => $article,
            'site' => $article->site,
            'categories' => $article->site->categories()->orderBy('name')->get(),
            'selectedCategories' => $article->categories->pluck('id')->all(),
            'contentImages' => HtmlContent::make($article->content)->images(),
            'issues' => $article->openIssues()->get(),
            'settings' => AuditSettings::forUser($article->site?->user),
            'lockState' => $article->lockStateFor($user),
            'canEdit' => $user->can('update', $article),
            'heartbeatSeconds' => (int) config('articleguard.locks.heartbeat_seconds', 60),
        ]);
    }

    /**
     * Enregistre les modifications vers WordPress, puis relance l'audit.
     */
    public function update(UpdateArticleRequest $request, WordpressArticle $article, ArticleSaveService $saves): JsonResponse
    {
        // WordPress peut mettre plusieurs dizaines de secondes à enregistrer :
        // l'envoi part en arrière-plan et l'éditeur rend la main tout de suite.
        // Rien à envoyer : réponse immédiate, inutile de lancer un processus.
        if ($saves->supportsBackground() && $this->articles->buildPayload($article, $request->validated()) !== []) {
            $token = $saves->queue($article, $request->user(), $request->validated());

            if ($token !== null) {
                return response()->json([
                    'ok' => true,
                    'pending' => true,
                    'message' => 'Envoi à WordPress en arrière-plan…',
                    'status_url' => route('articles.save-status', [$article, $token]),
                ], 202);
            }
        }

        // Écriture (jusqu'à `write_timeout`) puis relecture de vérification :
        // la limite PHP par défaut couperait la requête avant la fin et le
        // navigateur ne recevrait qu'une page d'erreur.
        @set_time_limit((int) config('articleguard.http.write_timeout', 90) + 90);

        try {
            $result = $saves->save($article, $request->validated());
        } catch (WordPressApiException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], $e->status && $e->status < 500 ? $e->status : 502);
        }

        $article = $result['article']->load('categories');

        return response()->json([
            'ok' => true,
            'message' => ArticleSaveService::message($result),
            'changed' => $result['changed'],
            'audit' => $this->auditPayload($article),
        ]);
    }

    /**
     * « Vider le cache » de l'éditeur : la page publique de l'article est
     * régénérée au prochain passage d'un visiteur.
     */
    public function purgeCache(WordpressArticle $article, ArticleSaveService $saves): JsonResponse
    {
        $this->authorize('audit', $article);

        if (! $article->site?->hasCredentials()) {
            return response()->json(['ok' => false, 'message' => 'Aucun identifiant WordPress enregistré pour ce site.'], 422);
        }

        $purged = $saves->purgeCache($article);

        if ($purged === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Cache non vidé : installez l’extension « ArticleGuard Cache Bridge » sur le site (page Sites WordPress), ou videz le cache depuis WordPress.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Cache de l’article vidé ('.implode(', ', $purged).').',
            'caches' => $purged,
        ]);
    }

    /**
     * Suivi d'un enregistrement en arrière-plan, interrogé par l'éditeur.
     *
     * Seul l'auteur de l'enregistrement peut le suivre. Si le processus
     * détaché n'a jamais démarré, l'enregistrement est exécuté ici même : il
     * n'est jamais perdu.
     */
    public function saveStatus(Request $request, WordpressArticle $article, string $token, ArticleSaveService $saves): JsonResponse
    {
        $this->authorize('view', $article);

        $entry = $saves->status($token);

        if ($entry === null || $entry['article_id'] !== $article->id || $entry['user_id'] !== $request->user()->id) {
            return response()->json(['ok' => false, 'message' => 'Enregistrement introuvable ou expiré.'], 404);
        }

        if ($saves->neverStarted($entry)) {
            @set_time_limit((int) config('articleguard.http.write_timeout', 90) + 90);

            $saves->process($token);
            $entry = $saves->status($token) ?? $entry;
        }

        return match ($entry['status']) {
            ArticleSaveService::STATUS_DONE => response()->json([
                'ok' => true,
                'status' => 'done',
                'message' => $entry['message'],
                'changed' => $entry['changed'] ?? [],
                'audit' => $this->auditPayload($article->refresh()->load('categories')),
            ]),
            ArticleSaveService::STATUS_FAILED => response()->json([
                'ok' => false,
                'status' => 'failed',
                'message' => $entry['message'],
            ], (int) ($entry['code'] ?? 502)),
            default => response()->json(['ok' => true, 'status' => 'pending']),
        };
    }

    /**
     * Relance un audit complet (règles réseau comprises) sur un article.
     */
    public function auditArticle(WordpressArticle $article): JsonResponse
    {
        $this->authorize('audit', $article);

        @set_time_limit(180);

        // Version actuelle de WordPress (une correction faite directement dans
        // l'administration WordPress compte aussi), images re-téléchargées.
        $this->sync->refreshQuietly($article);

        $this->audit->run(
            $article->refresh(),
            AuditSettings::forUser($article->site?->user),
            allowNetwork: true,
            trigger: 'manual',
            freshImages: true,
        );

        $article->refresh();

        return response()->json([
            'ok' => true,
            'message' => 'Audit terminé.',
            'audit' => $this->auditPayload($article),
            'row' => view('articles.partials.row', ['article' => $article->load(WordpressArticle::ROW_RELATIONS)])->render(),
        ]);
    }

    /**
     * « Corrigé » posé à la main depuis le tableau.
     *
     * L'agent qui a fini de corriger (« À corriger ») ou de vérifier
     * (« À vérifier ») un article le déclare corrigé : l'article est libéré,
     * les erreurs corrigées sont enregistrées à son nom, et il disparaît de la
     * liste des agents jusqu'à une éventuelle réassignation par l'Admin.
     */
    public function updateStatus(Request $request, WordpressArticle $article, ArticleCompletionService $completion): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::in([WordpressArticle::STATUS_DONE])],
        ], [
            'status.in' => 'Statut inconnu.',
            'status.required' => 'Statut inconnu.',
        ]);

        $this->authorize('setStatus', $article);

        if (! $article->statusIsEditable()) {
            return response()->json([
                'ok' => false,
                'message' => $article->isCompleted()
                    ? 'Cet article est déjà déclaré corrigé.'
                    : 'Cet article n’a pas encore été analysé : lancez l’audit avant de le déclarer corrigé.',
            ], 422);
        }

        try {
            $article = $completion->complete($article, $request->user());
        } catch (ArticleLockedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
        }

        $isAdmin = $request->user()->isAdmin();

        return response()->json([
            'ok' => true,
            'message' => $isAdmin
                ? 'Article marqué comme corrigé.'
                : 'Article marqué comme corrigé : il est transmis à l’administrateur pour vérification.',
            // Côté agent, la ligne quitte la liste.
            'removed' => ! $isAdmin,
            'row' => $isAdmin
                ? view('articles.partials.row', ['article' => $article->load(WordpressArticle::ROW_RELATIONS)])->render()
                : null,
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
            ->whereHas('site', fn ($query) => $query->accessibleBy($request->user()))
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
     * Images de l'article (à la une + contenu) avec leur verdict de qualité,
     * pour le modal ouvert depuis la miniature du tableau.
     */
    public function images(WordpressArticle $article, ArticleImageReport $report): JsonResponse
    {
        $this->authorize('view', $article);

        $article->loadMissing('site.user');
        $images = $report->build($article);

        return response()->json([
            'title' => $article->title,
            'count' => count($images),
            'edit_url' => route('articles.edit', $article),
            'html' => view('articles.partials.images-list', ['images' => $images, 'article' => $article])->render(),
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
            ->with(WordpressArticle::ROW_RELATIONS)
            ->search($filters['search'])
            ->inCategories($filters['categories'], $filters['mode'])
            ->withDisplayStatus($filters['status'])
            ->visibleInListTo(auth()->user(), $filters['status'])
            ->forAgent($filters['agent'])
            ->orderByDesc('wordpress_published_at')
            ->orderByDesc('wp_id')
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    /**
     * Nombre d'articles par catégorie, compte tenu des autres filtres actifs.
     *
     * Le compteur affiché à côté d'une catégorie doit répondre à la question
     * « combien d'articles obtiendrais-je en cochant celle-ci ? ». Le filtre
     * de catégorie est donc exclu de son propre calcul en mode « au moins
     * une » — sinon cocher « Bagues » ramènerait toutes les autres à zéro.
     * En mode « toutes », les catégories déjà cochées restent appliquées,
     * puisque la sélection s'y cumule.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, int> total indexé par identifiant de catégorie
     */
    protected function categoryCounts(WordpressSite $site, array $filters): array
    {
        $articles = $site->articles()
            ->search($filters['search'])
            ->withDisplayStatus($filters['status'])
            ->visibleInListTo(auth()->user(), $filters['status'])
            ->forAgent($filters['agent'])
            ->when(
                $filters['mode'] === 'all' && $filters['categories'] !== [],
                fn ($query) => $query->inCategories($filters['categories'], 'all'),
            );

        return DB::table('article_category')
            ->whereIn('wordpress_article_id', $articles->select('wordpress_articles.id'))
            ->groupBy('wordpress_category_id')
            ->selectRaw('wordpress_category_id, count(*) as total')
            ->pluck('total', 'wordpress_category_id')
            ->map(fn ($total) => (int) $total)
            ->all();
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
            'status' => in_array($request->query('status'), WordpressArticle::STATUS_FILTERS, true)
                ? (string) $request->query('status')
                : 'all',
            'agent' => $this->agentFilter($request),
            'per_page' => in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 20,
        ];
    }

    /**
     * Filtre d'agent : `none` (disponibles), `mine` (les miens) ou
     * l'identifiant d'un compte. Une valeur inconnue est ignorée plutôt que de
     * vider le tableau sans explication.
     */
    protected function agentFilter(Request $request): int|string|null
    {
        $agent = $request->query('agent');

        return match (true) {
            $agent === 'none' => 'none',
            $agent === 'mine' => $request->user()->id,
            is_numeric($agent) && User::query()->whereKey((int) $agent)->exists() => (int) $agent,
            default => null,
        };
    }

    protected function resolveSite(Request $request): ?WordpressSite
    {
        if ($request->filled('site')) {
            $site = WordpressSite::query()->accessibleBy($request->user())->find($request->integer('site'));

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
                'settings' => AuditSettings::forUser($article->site?->user),
            ])->render(),
        ];
    }
}
