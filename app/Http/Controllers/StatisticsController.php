<?php

namespace App\Http\Controllers;

use App\Services\Stats\ArticleStatisticsService;
use App\Services\Stats\CorrectionStatsService;
use App\Support\AgentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Statistiques des articles : état courant et historique des corrections.
 */
class StatisticsController extends Controller
{
    public function __construct(
        protected ArticleStatisticsService $statistics,
        protected CorrectionStatsService $corrections,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $granularity = $this->corrections->normalize($request->query('granularity'));
        $siteId = $this->siteFilter($request);
        $status = $request->query('status');
        $agent = $this->agentFilter($request);

        return view('statistics.index', [
            'overview' => $this->statistics->overview($user),
            'sites' => $this->statistics->perSite($user),
            'archivedSites' => $this->statistics->archivedSites($user),
            'agentRows' => $this->statistics->perAgent($user, $siteId),
            'history' => $this->statistics->history($user, $siteId, $status, $agent),
            'historyTotals' => $this->statistics->historyTotals($user, $siteId, $status, $agent),
            'pending' => $this->statistics->pendingArticles($user, $siteId),
            'series' => $this->corrections->series($user, $granularity, siteId: $siteId),
            'summary' => $this->corrections->summary($user, $siteId),
            'granularity' => $granularity,
            'siteFilter' => $siteId,
            'statusFilter' => $status,
            'agentFilter' => $agent,
            'agents' => AgentCatalog::all(),
            'userSites' => $user->sites()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Série des corrections, rechargée en AJAX au changement de période.
     */
    public function series(Request $request): JsonResponse
    {
        $user = $request->user();
        $granularity = $this->corrections->normalize($request->query('granularity'));
        $siteId = $this->siteFilter($request);

        return response()->json([
            'ok' => true,
            'granularity' => $granularity,
            'series' => $this->corrections->series($user, $granularity, siteId: $siteId),
            'summary' => $this->corrections->summary($user, $siteId)[$granularity],
        ]);
    }

    /**
     * Le filtre de site est une entrée utilisateur : un identifiant qui
     * n'appartient pas au compte est ignoré plutôt que de faire fuiter la
     * présence d'un site tiers.
     */
    protected function siteFilter(Request $request): ?int
    {
        $siteId = $request->query('site');

        if (! is_numeric($siteId)) {
            return null;
        }

        return $request->user()->sites()->whereKey((int) $siteId)->value('id');
    }

    /**
     * `none` isole les corrections faites hors assignation ; un nom qui n'est
     * plus configuré reste accepté, sans quoi l'historique d'un agent retiré
     * deviendrait inaccessible.
     */
    protected function agentFilter(Request $request): ?string
    {
        $agent = trim((string) $request->query('agent', ''));

        return $agent === '' ? null : $agent;
    }
}
