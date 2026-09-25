<?php

namespace App\Services\Stats;

use App\Models\ArticleAssignment;
use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Statistiques des articles : combien sont conformes, combien restent à
 * corriger, qui travaille sur quoi, et l'historique des corrections.
 *
 * Deux sources cohabitent, volontairement :
 *
 *  - l'état courant vient des articles synchronisés : c'est la réalité du
 *    site à l'instant T (460 articles, dont 400 conformes et 60 à corriger) ;
 *  - l'historique vient d'`article_status_history`, qui survit à la
 *    suppression d'un site et garde la trace de chaque correction.
 *
 * Toutes les méthodes reçoivent un StatisticsFilter, qui porte le lecteur :
 * un Agent n'obtient jamais que ses propres données, quelle que soit la
 * requête.
 */
class ArticleStatisticsService
{
    /**
     * Totaux tous sites confondus (ou pour le site filtré).
     *
     * @return array{articles: int, ok: int, fixed: int, corrected: int, needs_fix: int, pending: int, in_progress: int, rate: int}
     */
    public function overview(StatisticsFilter $filter): array
    {
        // Vue d'ensemble de l'Admin : l'état des sites, indépendamment de
        // l'agent filtré. Un Agent n'y voit que les articles qu'il a en main.
        $articles = $filter->viewer->isAdmin()
            ? WordpressArticle::query()->when($filter->siteId, fn (Builder $q) => $q->where('wordpress_site_id', $filter->siteId))
            : $this->articles($filter);

        $counts = (clone $articles)
            ->selectRaw('audit_status, count(*) as total')
            ->groupBy('audit_status')
            ->pluck('total', 'audit_status');

        $ok = (int) $counts->get(WordpressArticle::AUDIT_OK, 0);
        $fixed = (int) $counts->get(WordpressArticle::AUDIT_FIXED, 0);
        $needsFix = (int) $counts->get(WordpressArticle::AUDIT_NEEDS_FIX, 0);
        $pending = (int) $counts->get(WordpressArticle::AUDIT_PENDING, 0);
        $total = $ok + $fixed + $needsFix + $pending;

        return [
            'articles' => $total,
            'ok' => $ok,
            'fixed' => $fixed,
            'corrected' => $ok + $fixed,
            'needs_fix' => $needsFix,
            'pending' => $pending,
            'in_progress' => (clone $articles)->locked()->count(),
            // Part d'articles conformes, arrondie : sert la barre de progression.
            'rate' => $total > 0 ? (int) round((($ok + $fixed) / $total) * 100) : 0,
        ];
    }

    /**
     * Statistiques personnelles d'un agent.
     *
     * @return array{corrected: int, ok: int, fixed: int, in_progress: int, to_fix: int, week: int, month: int, completed: int, last_activity: ?Carbon}
     */
    public function agentOverview(StatisticsFilter $filter): array
    {
        $agentId = $filter->agentId() ?? $filter->viewer->id;
        $now = CarbonImmutable::now();

        $history = ArticleStatusHistory::query()
            ->where('agent_user_id', $agentId)
            ->when($filter->siteId, fn (Builder $q) => $q->where('wordpress_site_id', $filter->siteId));

        $row = (clone $history)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as ok', [WordpressArticle::AUDIT_OK])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as fixed', [WordpressArticle::AUDIT_FIXED])
            ->first();

        $locked = WordpressArticle::query()
            ->locked()
            ->where('assigned_to', $agentId)
            ->when($filter->siteId, fn (Builder $q) => $q->where('wordpress_site_id', $filter->siteId));

        return [
            'corrected' => (int) ($row->total ?? 0),
            'ok' => (int) ($row->ok ?? 0),
            'fixed' => (int) ($row->fixed ?? 0),
            'in_progress' => (clone $locked)->count(),
            'to_fix' => (clone $locked)->where('audit_status', WordpressArticle::AUDIT_NEEDS_FIX)->count(),
            'week' => (clone $history)->where('recorded_at', '>=', $now->startOfWeek())->count(),
            'month' => (clone $history)->where('recorded_at', '>=', $now->startOfMonth())->count(),
            'completed' => ArticleAssignment::query()
                ->where('user_id', $agentId)
                ->whereNotNull('completed_at')
                ->when($filter->siteId, fn (Builder $q) => $q->where('wordpress_site_id', $filter->siteId))
                ->count(),
            'last_activity' => $this->lastActivity($agentId),
        ];
    }

    /**
     * Détail par site connecté, du moins conforme au plus conforme : les sites
     * qui demandent du travail arrivent en tête. Réservé à l'Admin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perSite(StatisticsFilter $filter): array
    {
        if (! $filter->viewer->isAdmin()) {
            return [];
        }

        $sites = WordpressSite::query()->orderBy('name')->get();

        if ($sites->isEmpty()) {
            return [];
        }

        $counts = WordpressArticle::query()
            ->whereIn('wordpress_site_id', $sites->pluck('id'))
            ->selectRaw('wordpress_site_id, audit_status, count(*) as total')
            ->groupBy('wordpress_site_id', 'audit_status')
            ->get()
            ->groupBy('wordpress_site_id');

        $inProgress = WordpressArticle::query()
            ->locked()
            ->selectRaw('wordpress_site_id, count(*) as total')
            ->groupBy('wordpress_site_id')
            ->pluck('total', 'wordpress_site_id');

        // Dernière correction connue par site, prise dans l'historique.
        $lastCorrections = ArticleStatusHistory::query()
            ->whereIn('wordpress_site_id', $sites->pluck('id'))
            ->selectRaw('wordpress_site_id, max(recorded_at) as last_recorded_at')
            ->groupBy('wordpress_site_id')
            ->pluck('last_recorded_at', 'wordpress_site_id');

        $rows = $sites->map(function ($site) use ($counts, $lastCorrections, $inProgress) {
            $byStatus = ($counts->get($site->id) ?? collect())->pluck('total', 'audit_status');

            $ok = (int) $byStatus->get(WordpressArticle::AUDIT_OK, 0);
            $fixed = (int) $byStatus->get(WordpressArticle::AUDIT_FIXED, 0);
            $needsFix = (int) $byStatus->get(WordpressArticle::AUDIT_NEEDS_FIX, 0);
            $pending = (int) $byStatus->get(WordpressArticle::AUDIT_PENDING, 0);
            $total = $ok + $fixed + $needsFix + $pending;

            return [
                'site' => $site,
                'name' => $site->name,
                'url' => $site->url,
                'articles' => $total,
                'ok' => $ok,
                'fixed' => $fixed,
                'corrected' => $ok + $fixed,
                'needs_fix' => $needsFix,
                'pending' => $pending,
                'in_progress' => (int) ($inProgress[$site->id] ?? 0),
                'rate' => $total > 0 ? (int) round((($ok + $fixed) / $total) * 100) : 0,
                'last_corrected_at' => $lastCorrections[$site->id] ?? null,
                'archived' => false,
            ];
        });

        return $rows->sortBy('rate')->values()->all();
    }

    /**
     * Sites supprimés dont l'historique subsiste. Réservé à l'Admin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function archivedSites(StatisticsFilter $filter): array
    {
        if (! $filter->viewer->isAdmin()) {
            return [];
        }

        return ArticleStatusHistory::query()
            ->whereNull('wordpress_site_id')
            ->selectRaw('site_name, site_url, count(*) as corrected, max(recorded_at) as last_recorded_at')
            ->groupBy('site_name', 'site_url')
            ->orderByDesc('last_recorded_at')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->site_name,
                'url' => $row->site_url,
                'corrected' => (int) $row->corrected,
                'last_corrected_at' => $row->last_recorded_at,
                'archived' => true,
            ])
            ->all();
    }

    /**
     * Articles restant à corriger, les plus chargés en premier.
     *
     * Avec un filtre d'agent — toujours le cas pour un Agent —, ce sont les
     * articles qu'il a en main ; `none` isole ceux que personne n'a pris.
     *
     * @return LengthAwarePaginator<int, WordpressArticle>
     */
    public function pendingArticles(StatisticsFilter $filter, int $perPage = 10): LengthAwarePaginator
    {
        return $this->articles($filter)
            ->where('audit_status', WordpressArticle::AUDIT_NEEDS_FIX)
            ->with(['site:id,name,url', 'assignee:id,name'])
            ->orderByDesc('issues_count')
            ->orderByDesc('last_audited_at')
            ->paginate($perPage, ['*'], 'pending_page')
            ->withQueryString();
    }

    /**
     * Articles en cours de traitement, les plus anciennes prises d'abord.
     *
     * @return LengthAwarePaginator<int, WordpressArticle>
     */
    public function inProgressArticles(StatisticsFilter $filter, int $perPage = 10): LengthAwarePaginator
    {
        return $this->articles($filter)
            ->locked()
            ->with(['site:id,name,url', 'assignee:id,name'])
            ->orderBy('locked_at')
            ->paginate($perPage, ['*'], 'progress_page')
            ->withQueryString();
    }

    /**
     * Historique des corrections, sites supprimés compris.
     *
     * @return LengthAwarePaginator<int, ArticleStatusHistory>
     */
    public function history(StatisticsFilter $filter, int $perPage = 15): LengthAwarePaginator
    {
        return $this->historyQuery($filter)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'history_page')
            ->withQueryString();
    }

    /**
     * Totaux de l'historique, indépendants de l'état courant : ils incluent
     * les sites supprimés.
     *
     * @return array{total: int, ok: int, fixed: int, manual: int, sites: int}
     */
    public function historyTotals(StatisticsFilter $filter): array
    {
        $row = $this->historyQuery($filter)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as ok', [WordpressArticle::AUDIT_OK])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as fixed', [WordpressArticle::AUDIT_FIXED])
            ->selectRaw('sum(case when resolved_manually = 1 then 1 else 0 end) as manual')
            ->selectRaw('count(distinct site_name) as sites')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'ok' => (int) ($row->ok ?? 0),
            'fixed' => (int) ($row->fixed ?? 0),
            'manual' => (int) ($row->manual ?? 0),
            'sites' => (int) ($row->sites ?? 0),
        ];
    }

    /**
     * Corrections, articles en cours et dernière activité par agent.
     * Réservé à l'Admin.
     *
     * Les agents sans correction sont conservés : une case vide est une
     * information, et la liste ne doit pas changer de forme d'une visite à
     * l'autre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perAgent(StatisticsFilter $filter): array
    {
        if (! $filter->viewer->isAdmin()) {
            return [];
        }

        $base = ArticleStatusHistory::query()
            ->when($filter->siteId, fn (Builder $q) => $q->where('wordpress_site_id', $filter->siteId))
            ->when($filter->from, fn (Builder $q) => $q->where('recorded_at', '>=', $filter->from))
            ->when($filter->to, fn (Builder $q) => $q->where('recorded_at', '<=', $filter->to));

        $byUser = (clone $base)
            ->whereNotNull('agent_user_id')
            ->selectRaw('agent_user_id, count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as ok', [WordpressArticle::AUDIT_OK])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as fixed', [WordpressArticle::AUDIT_FIXED])
            ->selectRaw('max(recorded_at) as last_recorded_at')
            ->groupBy('agent_user_id')
            ->get()
            ->keyBy('agent_user_id');

        $inProgress = WordpressArticle::query()
            ->locked()
            ->when($filter->siteId, fn (Builder $q) => $q->where('wordpress_site_id', $filter->siteId))
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $lastTaken = ArticleAssignment::query()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, max(taken_at) as last_taken_at, max(released_at) as last_released_at')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // Uniquement les comptes Agents : un compte créé par l'Admin apparaît
        // aussitôt, même sans activité.
        $users = User::query()
            ->agents()
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'is_active']);

        return $users->map(function (User $user) use ($byUser, $inProgress, $lastTaken) {
            $stats = $byUser->get($user->id);
            $sessions = $lastTaken->get($user->id);

            return [
                'agent' => $user->id,
                'label' => $user->name.($user->is_active ? '' : ' (désactivé)'),
                'user' => $user,
                'total' => (int) ($stats->total ?? 0),
                'ok' => (int) ($stats->ok ?? 0),
                'fixed' => (int) ($stats->fixed ?? 0),
                'in_progress' => (int) ($inProgress[$user->id] ?? 0),
                'last_recorded_at' => $stats->last_recorded_at ?? null,
                'last_activity' => $this->latest([
                    $stats->last_recorded_at ?? null,
                    $sessions->last_taken_at ?? null,
                    $sessions->last_released_at ?? null,
                ]),
            ];
        })->values()->all();
    }

    /**
     * Base commune à l'historique et à ses totaux, pour que le résumé affiché
     * corresponde toujours aux lignes listées.
     *
     * @return Builder<ArticleStatusHistory>
     */
    protected function historyQuery(StatisticsFilter $filter): Builder
    {
        $agent = $filter->agent();

        return ArticleStatusHistory::query()
            ->when($filter->siteId, fn (Builder $query) => $query->where('wordpress_site_id', $filter->siteId))
            ->when(
                in_array($filter->status, [WordpressArticle::AUDIT_OK, WordpressArticle::AUDIT_FIXED], true),
                fn (Builder $query) => $query->where('status', $filter->status),
            )
            ->when($filter->from, fn (Builder $query) => $query->where('recorded_at', '>=', $filter->from))
            ->when($filter->to, fn (Builder $query) => $query->where('recorded_at', '<=', $filter->to))
            // `none` isole les corrections faites hors prise en charge.
            ->when($agent === 'none', fn (Builder $query) => $query->whereNull('agent_user_id')->whereNull('agent'))
            ->when(is_int($agent), fn (Builder $query) => $query->where('agent_user_id', $agent));
    }

    /**
     * Articles de l'espace, restreints par le site et l'agent du filtre.
     *
     * @return Builder<WordpressArticle>
     */
    protected function articles(StatisticsFilter $filter): Builder
    {
        $agent = $filter->agent();

        return WordpressArticle::query()
            ->when($filter->siteId, fn (Builder $q) => $q->where('wordpress_site_id', $filter->siteId))
            ->when($agent === 'none', fn (Builder $q) => $q->available())
            ->when(is_int($agent), fn (Builder $q) => $q->locked()->where('assigned_to', $agent));
    }

    protected function lastActivity(int $userId): ?Carbon
    {
        $assignment = ArticleAssignment::query()
            ->where('user_id', $userId)
            ->selectRaw('max(taken_at) as taken, max(released_at) as released')
            ->first();

        $history = ArticleStatusHistory::query()
            ->where('agent_user_id', $userId)
            ->max('recorded_at');

        return $this->latest([$assignment->taken ?? null, $assignment->released ?? null, $history]);
    }

    /**
     * @param  array<int, mixed>  $dates
     */
    protected function latest(array $dates): ?Carbon
    {
        $dates = array_filter(array_map(
            fn ($date) => $date ? Carbon::parse($date) : null,
            $dates,
        ));

        if ($dates === []) {
            return null;
        }

        usort($dates, fn (Carbon $a, Carbon $b) => $b <=> $a);

        return $dates[0];
    }
}
