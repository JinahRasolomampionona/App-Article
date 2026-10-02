<?php

namespace App\Services\Stats;

use App\Models\ArticleAssignment;
use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Carbon\CarbonInterface;
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
     * Les quatre cartes du Dashboard et des Statistiques, selon le statut
     * affiché dans « Articles » : une carte mène au tableau filtré sur le
     * même statut, les deux chiffres doivent donc être calculés pareil.
     *
     * @param  int|null  $siteId  `null` : tous les sites
     * @return array{total: int, needs_fix: int, to_review: int, fixed: int, pending: int}
     */
    public function statusCards(?int $siteId): array
    {
        $row = $this->statusCounts(
            WordpressArticle::query()->when($siteId, fn (Builder $q) => $q->where('wordpress_site_id', $siteId))
        )->first();

        return $this->statusRow($row);
    }

    /**
     * Comptages des quatre cartes, sur la requête donnée (éventuellement
     * groupée par site) : une seule définition pour les cartes et le tableau
     * « Par site ».
     *
     * @param  Builder<WordpressArticle>  $query
     * @return Builder<WordpressArticle>
     */
    protected function statusCounts(Builder $query): Builder
    {
        return $query
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when completed_at is not null then 1 else 0 end) as done')
            ->selectRaw('sum(case when completed_at is null and audit_status = ? then 1 else 0 end) as needs_fix', [WordpressArticle::AUDIT_NEEDS_FIX])
            ->selectRaw('sum(case when completed_at is null and audit_status in (?, ?) then 1 else 0 end) as to_review', [WordpressArticle::AUDIT_OK, WordpressArticle::AUDIT_FIXED])
            ->selectRaw('sum(case when completed_at is null and audit_status = ? then 1 else 0 end) as pending', [WordpressArticle::AUDIT_PENDING]);
    }

    /**
     * @return array{total: int, needs_fix: int, to_review: int, fixed: int, pending: int}
     */
    protected function statusRow(?object $row): array
    {
        return [
            'total' => (int) ($row->total ?? 0),
            'needs_fix' => (int) ($row->needs_fix ?? 0),
            'to_review' => (int) ($row->to_review ?? 0),
            'fixed' => (int) ($row->done ?? 0),
            'pending' => (int) ($row->pending ?? 0),
        ];
    }

    /**
     * Articles d'un agent pour la vue « Voir » de l'Admin : ceux qu'il traite
     * en ce moment et ceux qu'il a déclarés corrigés, tous sites confondus
     * (ou sur le site filtré).
     *
     * @return LengthAwarePaginator<int, WordpressArticle>
     */
    public function agentArticles(
        User $agent,
        ?int $siteId,
        int $perPage = 20,
        ?CarbonInterface $correctedFrom = null,
        ?CarbonInterface $correctedTo = null,
    ): LengthAwarePaginator {
        // Filtre de date : seuls les articles corrigés dans la période, les
        // articles en cours n'ayant pas encore de date de correction.
        $byDate = $correctedFrom !== null || $correctedTo !== null;

        return WordpressArticle::query()
            ->when($siteId, fn (Builder $q) => $q->where('wordpress_site_id', $siteId))
            ->when($byDate, fn (Builder $q) => $q
                ->where('completed_by', $agent->id)
                ->when($correctedFrom, fn (Builder $q) => $q->where('completed_at', '>=', $correctedFrom->startOfDay()))
                ->when($correctedTo, fn (Builder $q) => $q->where('completed_at', '<=', $correctedTo->endOfDay())))
            ->unless($byDate, fn (Builder $q) => $q->where(function (Builder $q) use ($agent) {
                $q->where('completed_by', $agent->id)
                    ->orWhere(fn (Builder $inner) => $inner->locked()->where('assigned_to', $agent->id));
            }))
            ->with(['site:id,name,url', 'openIssues:id,wordpress_article_id,rule_type,severity,message', 'notes.author:id,name', 'assignee:id,name'])
            // En cours d'abord, puis les corrections les plus récentes.
            ->orderByRaw('case when completed_at is null then 0 else 1 end')
            ->orderByDesc('completed_at')
            ->orderByDesc('locked_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Détail par site connecté, sur le principe des quatre cartes : Articles,
     * Non corrigés (« À corriger »), Non vérifiés (« À vérifier »), Corrigés
     * (déclarés corrigés par un agent). Les sites qui ont le plus d'articles à
     * corriger arrivent en tête. Réservé à l'Admin.
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

        $counts = $this->statusCounts(
            WordpressArticle::query()
                ->whereIn('wordpress_site_id', $sites->pluck('id'))
                ->addSelect('wordpress_site_id')
                ->selectRaw('max(completed_at) as last_completed_at')
                ->groupBy('wordpress_site_id')
        )->get()->keyBy('wordpress_site_id');

        // Repli sur l'historique : un article corrigé puis réassigné n'a plus
        // de date de correction, mais la correction a bien eu lieu.
        $lastCorrections = ArticleStatusHistory::query()
            ->whereIn('wordpress_site_id', $sites->pluck('id'))
            ->selectRaw('wordpress_site_id, max(recorded_at) as last_recorded_at')
            ->groupBy('wordpress_site_id')
            ->pluck('last_recorded_at', 'wordpress_site_id');

        $rows = $sites->map(function ($site) use ($counts, $lastCorrections) {
            $row = $counts->get($site->id);
            $status = $this->statusRow($row);

            $dates = array_filter([$row->last_completed_at ?? null, $lastCorrections[$site->id] ?? null]);

            return [
                'site' => $site,
                'name' => $site->name,
                'url' => $site->url,
                'articles' => $status['total'],
                'needs_fix' => $status['needs_fix'],
                'to_review' => $status['to_review'],
                'fixed' => $status['fixed'],
                'pending' => $status['pending'],
                'last_corrected_at' => $dates === [] ? null : max(array_map(fn ($date) => (string) $date, $dates)),
                'archived' => false,
            ];
        });

        return $rows->sortBy([['needs_fix', 'desc'], ['to_review', 'desc'], ['name', 'asc']])->values()->all();
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
