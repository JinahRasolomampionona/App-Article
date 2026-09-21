<?php

namespace App\Http\Controllers;

use App\Jobs\AuditSiteJob;
use App\Models\ArticleAuditIssue;
use App\Services\QueueWorkerLauncher;
use App\Services\SiteContext;
use App\Support\IssueCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __construct(
        protected SiteContext $context,
        protected QueueWorkerLauncher $worker,
    ) {}

    public function index(Request $request): View
    {
        $site = $this->context->current();

        if ($site === null) {
            return view('audits.index', [
                'site' => null,
                'issues' => null,
                'summary' => [],
                'ruleFilter' => null,
            ]);
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
            'issues' => $issues,
            'summary' => $summary,
            'ruleFilter' => $ruleFilter,
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
}
