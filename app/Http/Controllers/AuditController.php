<?php

namespace App\Http\Controllers;

use App\Jobs\AuditSiteJob;
use App\Models\ArticleAudit;
use App\Models\ArticleAuditIssue;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\QueueWorkerLauncher;
use App\Services\SiteContext;
use App\Support\IssueCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Page Audits (Admin) : les problèmes ouverts du site, et l'historique des
 * scans de chaque article — premier scan, dernier scan, nombre de scans —
 * pour repérer les articles analysés il y a longtemps ou jamais.
 */
class AuditController extends Controller
{
    /** Filtres d'ancienneté du dernier scan. */
    protected const PERIODS = ['today', 'week', 'month', 'older', 'never'];

    public function __construct(
        protected SiteContext $context,
        protected QueueWorkerLauncher $worker,
    ) {}

    public function index(Request $request): View
    {
        $site = $this->context->current();
        $tab = $request->query('tab') === 'history' ? 'history' : 'issues';

        if ($site === null) {
            return view('audits.index', [
                'site' => null,
                'tab' => $tab,
                'issues' => null,
                'summary' => [],
                'ruleFilter' => null,
            ]);
        }

        if ($tab === 'history') {
            return view('audits.index', ['site' => $site, 'tab' => $tab] + $this->history($request, $site));
        }

        $ruleFilter = $request->query('rule');

        $issues = ArticleAuditIssue::query()
            ->whereNull('resolved_at')
            ->whereIn('wordpress_article_id', $site->articles()->select('id'))
            ->when($ruleFilter, fn ($query) => $query->where('rule_type', $ruleFilter))
            ->with('article:id,title,slug,link,wordpress_site_id,audit_status,issues_count')
            ->orderByDesc('detected_at')
            ->paginate(25)
            ->withQueryString();

        $summary = ArticleAuditIssue::query()
            ->whereNull('resolved_at')
            ->whereIn('wordpress_article_id', $site->articles()->select('id'))
            ->selectRaw('rule_type, count(*) as total')
            ->groupBy('rule_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->rule_type,
                'label' => IssueCatalog::label($row->rule_type),
                'total' => (int) $row->total,
            ])
            ->all();

        return view('audits.index', [
            'site' => $site,
            'tab' => $tab,
            'issues' => $issues,
            'summary' => $summary,
            'ruleFilter' => $ruleFilter,
        ]);
    }

    /**
     * Détail des scans d'un article (fenêtre ouverte depuis l'historique).
     */
    public function scans(WordpressArticle $article): JsonResponse
    {
        $this->authorize('view', $article);

        $scans = $article->scans()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'title' => $article->title,
            'total' => $article->scans()->count(),
            'html' => view('audits.partials.scans-list', [
                'scans' => $scans,
                'total' => $article->scans()->count(),
            ])->render(),
        ]);
    }

    /**
     * Relance l'audit de l'ensemble du site en file d'attente.
     */
    public function runForSite(Request $request): JsonResponse
    {
        $site = $this->context->current();

        if ($site === null) {
            return response()->json(['ok' => false, 'message' => 'Aucun site sélectionné.'], 422);
        }

        $this->authorize('sync', $site);

        AuditSiteJob::dispatch($site, onlyStale: $request->boolean('only_stale', false));
        $this->worker->ensureRunning();

        return response()->json([
            'ok' => true,
            'message' => 'Audit du site programmé. Les résultats apparaîtront au fur et à mesure.',
        ]);
    }

    /**
     * Historique des scans : un article par ligne, avec les dates de son
     * premier et de son dernier scan.
     *
     * @return array<string, mixed>
     */
    protected function history(Request $request, WordpressSite $site): array
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'period' => in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : '',
            // `recent` : dernier scan le plus récent d'abord ; `oldest` :
            // articles scannés il y a le plus longtemps (ou jamais) d'abord.
            'sort' => $request->query('sort') === 'oldest' ? 'oldest' : 'recent',
        ];

        /** @var LengthAwarePaginator<int, WordpressArticle> $articles */
        $articles = $site->articles()
            ->select(['id', 'wordpress_site_id', 'wp_id', 'title', 'slug', 'link', 'audit_status', 'issues_count', 'completed_at'])
            ->withCount('scans')
            ->withMin('scans', 'created_at')
            ->withMax('scans', 'created_at')
            ->search($filters['search'])
            ->when($filters['period'] !== '', fn (Builder $query) => $this->applyPeriod($query, $filters['period']))
            // Jamais scanné = le plus ancien : en tête du tri « plus ancien ».
            ->orderByRaw('scans_max_created_at is null '.($filters['sort'] === 'oldest' ? 'desc' : 'asc'))
            ->orderBy('scans_max_created_at', $filters['sort'] === 'oldest' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $scans = ArticleAudit::query()
            ->whereIn('wordpress_article_id', $site->articles()->select('id'));

        return [
            'articles' => $articles,
            'filters' => $filters,
            'totals' => [
                'scans' => (clone $scans)->count(),
                'first' => (clone $scans)->min('created_at'),
                'last' => (clone $scans)->max('created_at'),
                'never' => $site->articles()->whereDoesntHave('scans')->count(),
                'stale' => $site->articles()
                    ->whereHas('scans')
                    ->whereDoesntHave('scans', fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(30)))
                    ->count(),
            ],
        ];
    }

    /**
     * @param  Builder<WordpressArticle>  $query
     */
    protected function applyPeriod(Builder $query, string $period): void
    {
        match ($period) {
            'today' => $query->whereHas('scans', fn (Builder $q) => $q->where('created_at', '>=', now()->startOfDay())),
            'week' => $query->whereHas('scans', fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(7))),
            'month' => $query->whereHas('scans', fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(30))),
            // Scanné, mais pas depuis plus de 30 jours.
            'older' => $query->whereHas('scans')
                ->whereDoesntHave('scans', fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(30))),
            'never' => $query->whereDoesntHave('scans'),
            default => null,
        };
    }
}
