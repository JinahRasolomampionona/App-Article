<?php

namespace App\Http\Controllers;

use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressSite;
use App\Services\Stats\ArticleStatisticsService;
use App\Services\Stats\CorrectionStatsService;
use App\Services\Stats\StatisticsFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Statistiques des articles.
 *
 * - Admin : statistiques globales, filtrables par site, agent, date, statut ;
 * - Agent : uniquement ses propres statistiques.
 *
 * La restriction est portée par StatisticsFilter (construit ici à partir de
 * l'utilisateur connecté) : un Agent qui ajoute `?agent=12` à l'URL obtient
 * quand même ses propres chiffres, et rien des autres n'est rendu.
 */
class StatisticsController extends Controller
{
    public function __construct(
        protected ArticleStatisticsService $statistics,
        protected CorrectionStatsService $corrections,
    ) {}

    public function index(Request $request): View
    {
        $filter = StatisticsFilter::fromRequest($request);
        $granularity = $this->corrections->normalize($request->query('granularity'));

        if (! $request->user()->isAdmin()) {
            return $this->agentView($filter, $granularity);
        }

        $agentFilter = $filter->agent();

        return view('statistics.index', [
            'filter' => $filter,
            'overview' => $this->statistics->overview($filter),
            'sites' => $this->statistics->perSite($filter),
            'archivedSites' => $this->statistics->archivedSites($filter),
            'agentRows' => $this->statistics->perAgent($filter),
            'history' => $this->statistics->history($filter),
            'historyTotals' => $this->statistics->historyTotals($filter),
            'pending' => $this->statistics->pendingArticles($filter),
            'inProgress' => $this->statistics->inProgressArticles($filter),
            'series' => $this->corrections->series($filter, $granularity),
            'summary' => $this->corrections->summary($filter),
            'granularity' => $granularity,
            'siteFilter' => $filter->siteId,
            'statusFilter' => $filter->status,
            'agentFilter' => $agentFilter,
            'agentFilterLabel' => is_int($agentFilter) ? User::query()->whereKey($agentFilter)->value('name') : null,
            'agents' => User::query()->agents()->orderBy('name')->get(['id', 'name', 'is_active']),
            'userSites' => WordpressSite::query()->accessibleBy($filter->viewer, includeDone: true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Série des corrections, rechargée en AJAX au changement de période.
     */
    public function series(Request $request): JsonResponse
    {
        $filter = StatisticsFilter::fromRequest($request);
        $granularity = $this->corrections->normalize($request->query('granularity'));

        return response()->json([
            'ok' => true,
            'granularity' => $granularity,
            'series' => $this->corrections->series($filter, $granularity),
            'summary' => $this->corrections->summary($filter)[$granularity],
        ]);
    }

    /**
     * Espace personnel d'un agent : ses chiffres, ses articles, son
     * historique — rien d'autre n'est calculé ni envoyé au navigateur.
     */
    /**
     * Admin : efface l'historique des sites supprimés (lignes « Site
     * supprimé » du bloc « Par site »). Les sites connectés ne sont pas
     * touchés.
     */
    public function purgeArchivedSites(Request $request): RedirectResponse
    {
        $deleted = ArticleStatusHistory::query()->whereNull('wordpress_site_id')->delete();

        Log::info('Historique des sites supprimés effacé.', ['rows' => $deleted, 'by' => $request->user()->id]);

        return redirect()
            ->route('statistics.index')
            ->with('status', $deleted > 0
                ? 'Sites supprimés retirés des statistiques.'
                : 'Aucun site supprimé à retirer.');
    }

    protected function agentView(StatisticsFilter $filter, string $granularity): View
    {
        return view('statistics.agent', [
            'filter' => $filter,
            'me' => $this->statistics->agentOverview($filter),
            'history' => $this->statistics->history($filter),
            'historyTotals' => $this->statistics->historyTotals($filter),
            'pending' => $this->statistics->pendingArticles($filter),
            'inProgress' => $this->statistics->inProgressArticles($filter, onlyToFix: true),
            'series' => $this->corrections->series($filter, $granularity),
            'summary' => $this->corrections->summary($filter),
            'granularity' => $granularity,
            'siteFilter' => $filter->siteId,
            'statusFilter' => $filter->status,
            'agentFilter' => null,
            'userSites' => WordpressSite::query()->accessibleBy($filter->viewer, includeDone: true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
