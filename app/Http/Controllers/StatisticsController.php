<?php

namespace App\Http\Controllers;

use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressSite;
use App\Services\Stats\ArticleStatisticsService;
use App\Services\Stats\CorrectionStatsService;
use App\Services\Stats\StatisticsFilter;
use App\Support\AgentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Statistiques des articles — réservées à l'Admin (porte « admin » sur les
 * routes) : quatre cartes cliquables, activité par agent et par site, et le
 * détail des articles traités par chaque agent.
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

        return view('statistics.index', [
            'filter' => $filter,
            'cards' => $this->statistics->statusCards($filter->siteId),
            'sites' => $this->statistics->perSite($filter),
            'archivedSites' => $this->statistics->archivedSites($filter),
            'agentRows' => $this->statistics->perAgent($filter),
            'siteFilter' => $filter->siteId,
            'userSites' => WordpressSite::query()->accessibleBy($filter->viewer, includeDone: true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * « Voir » d'un agent : les articles qu'il traite et ceux qu'il a
     * déclarés corrigés, avec les erreurs corrigées et les commentaires —
     * l'Admin vérifie, commente et réassigne depuis cette page.
     */
    public function agent(Request $request, User $user): View
    {
        abort_unless($user->isAgent(), 404);

        $filter = StatisticsFilter::fromRequest($request);
        $articles = $this->statistics->agentArticles(
            $user,
            $filter->siteId,
            correctedFrom: $filter->from,
            correctedTo: $filter->to,
        );

        // Dernière fin de traitement de l'agent pour chaque article affiché :
        // la liste des erreurs qu'il a corrigées.
        $completions = ArticleStatusHistory::query()
            ->where('agent_user_id', $user->id)
            ->whereIn('wordpress_article_id', $articles->getCollection()->pluck('id'))
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->keyBy('wordpress_article_id');

        return view('statistics.agent', [
            'agent' => $user,
            'filter' => $filter,
            'articles' => $articles,
            'completions' => $completions,
            'siteFilter' => $filter->siteId,
            'withDates' => true,
            'userSites' => WordpressSite::query()->accessibleBy($filter->viewer, includeDone: true)->orderBy('name')->get(['id', 'name']),
            'agents' => AgentCatalog::all(),
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
}
