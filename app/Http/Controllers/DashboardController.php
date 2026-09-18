<?php

namespace App\Http\Controllers;

use App\Models\ArticleAuditIssue;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\SiteContext;
use App\Support\IssueCatalog;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected SiteContext $context,
    ) {}

    public function __invoke(): View
    {
        $site = $this->context->current();

        return view('dashboard.index', [
            'site' => $site,
            'stats' => $site ? $this->stats($site) : null,
            'distribution' => $site ? $this->distribution($site) : [],
            'recent' => $site ? $this->recent($site) : collect(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function stats(WordpressSite $site): array
    {
        $counts = $site->articles()
            ->selectRaw('audit_status, count(*) as total')
            ->groupBy('audit_status')
            ->pluck('total', 'audit_status');

        $total = (int) $counts->sum();

        return [
            'articles' => $total,
            'ok' => (int) $counts->get(WordpressArticle::AUDIT_OK, 0) + (int) $counts->get(WordpressArticle::AUDIT_FIXED, 0),
            'needs_fix' => (int) $counts->get(WordpressArticle::AUDIT_NEEDS_FIX, 0),
            'pending' => (int) $counts->get(WordpressArticle::AUDIT_PENDING, 0),
            'issues' => (int) $site->articles()->sum('issues_count'),
            'categories' => $site->categories()->count(),
            'last_sync_at' => $site->last_sync_at,
        ];
    }

    /**
     * Répartition des problèmes ouverts par type de règle.
     *
     * @return array<int, array{type: string, label: string, total: int}>
     */
    protected function distribution(WordpressSite $site): array
    {
        return ArticleAuditIssue::query()
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
    }

    /**
     * @return \Illuminate\Support\Collection<int, WordpressArticle>
     */
    protected function recent(WordpressSite $site)
    {
        return $site->articles()
            ->where('audit_status', WordpressArticle::AUDIT_NEEDS_FIX)
            ->with(['openIssues' => fn ($query) => $query->limit(4)])
            ->orderByDesc('issues_count')
            ->orderByDesc('last_audited_at')
            ->limit(6)
            ->get();
    }
}
