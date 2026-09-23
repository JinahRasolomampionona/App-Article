<?php

namespace App\Services\Stats;

use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Support\AgentCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Statistiques des articles : combien sont conformes, combien restent à
 * corriger, et l'historique des corrections.
 *
 * Deux sources cohabitent, volontairement :
 *
 *  - l'état courant vient des articles synchronisés : c'est la réalité du
 *    site à l'instant T (460 articles, dont 400 conformes et 60 à corriger) ;
 *  - l'historique vient d'`article_status_history`, qui survit à la
 *    suppression d'un site et garde la trace de chaque correction.
 *
 * Un site supprimé disparaît donc de l'état courant mais reste dans
 * l'historique, signalé comme archivé.
 */
class ArticleStatisticsService
{
    /**
     * Totaux tous sites confondus.
     *
     * @return array{articles: int, ok: int, fixed: int, corrected: int, needs_fix: int, pending: int, rate: int}
     */
    public function overview(User $user): array
    {
        $counts = WordpressArticle::query()
            ->whereIn('wordpress_site_id', $user->sites()->select('id'))
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
            // Part d'articles conformes, arrondie : sert la barre de progression.
            'rate' => $total > 0 ? (int) round((($ok + $fixed) / $total) * 100) : 0,
        ];
    }

    /**
     * Détail par site connecté, du moins conforme au plus conforme : les sites
     * qui demandent du travail arrivent en tête.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perSite(User $user): array
    {
        $sites = $user->sites()->orderBy('name')->get();

        if ($sites->isEmpty()) {
            return [];
        }

        $counts = WordpressArticle::query()
            ->whereIn('wordpress_site_id', $sites->pluck('id'))
            ->selectRaw('wordpress_site_id, audit_status, count(*) as total')
            ->groupBy('wordpress_site_id', 'audit_status')
            ->get()
            ->groupBy('wordpress_site_id');

        // Dernière correction connue par site, prise dans l'historique.
        $lastCorrections = ArticleStatusHistory::query()
            ->forUser($user->id)
            ->whereIn('wordpress_site_id', $sites->pluck('id'))
            ->selectRaw('wordpress_site_id, max(recorded_at) as last_recorded_at')
            ->groupBy('wordpress_site_id')
            ->pluck('last_recorded_at', 'wordpress_site_id');

        $rows = $sites->map(function ($site) use ($counts, $lastCorrections) {
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
                'rate' => $total > 0 ? (int) round((($ok + $fixed) / $total) * 100) : 0,
                'last_corrected_at' => $lastCorrections[$site->id] ?? null,
                'archived' => false,
            ];
        });

        return $rows->sortBy('rate')->values()->all();
    }

    /**
     * Sites supprimés dont l'historique subsiste.
     *
     * Leurs articles ont disparu avec le site : seul le nombre de corrections
     * enregistrées reste mesurable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function archivedSites(User $user): array
    {
        return ArticleStatusHistory::query()
            ->forUser($user->id)
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
     * @return LengthAwarePaginator<int, WordpressArticle>
     */
    public function pendingArticles(User $user, ?int $siteId = null, int $perPage = 10): LengthAwarePaginator
    {
        return WordpressArticle::query()
            ->whereIn('wordpress_site_id', $user->sites()->select('id'))
            ->when($siteId, fn (Builder $query) => $query->where('wordpress_site_id', $siteId))
            ->where('audit_status', WordpressArticle::AUDIT_NEEDS_FIX)
            ->with('site:id,name,url')
            ->orderByDesc('issues_count')
            ->orderByDesc('last_audited_at')
            ->paginate($perPage, ['*'], 'pending_page')
            ->withQueryString();
    }

    /**
     * Historique des corrections, sites supprimés compris.
     *
     * @return LengthAwarePaginator<int, ArticleStatusHistory>
     */
    public function history(
        User $user,
        ?int $siteId = null,
        ?string $status = null,
        ?string $agent = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->historyQuery($user, $siteId, $status, $agent)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'history_page')
            ->withQueryString();
    }

    /**
     * Base commune à l'historique et à ses totaux, pour que le résumé affiché
     * corresponde toujours aux lignes listées.
     *
     * @return Builder<ArticleStatusHistory>
     */
    protected function historyQuery(
        User $user,
        ?int $siteId = null,
        ?string $status = null,
        ?string $agent = null,
    ): Builder {
        return ArticleStatusHistory::query()
            ->forUser($user->id)
            ->when($siteId, fn (Builder $query) => $query->where('wordpress_site_id', $siteId))
            ->when(
                in_array($status, [WordpressArticle::AUDIT_OK, WordpressArticle::AUDIT_FIXED], true),
                fn (Builder $query) => $query->where('status', $status),
            )
            // `none` isole les corrections faites hors assignation.
            ->when($agent === 'none', fn (Builder $query) => $query->whereNull('agent'))
            ->when(
                $agent !== null && $agent !== '' && $agent !== 'none',
                fn (Builder $query) => $query->where('agent', $agent),
            );
    }

    /**
     * Corrections par agent, sur l'ensemble de l'historique du compte.
     *
     * Les agents sans correction sont conservés : une case vide est une
     * information, et la liste ne doit pas changer de forme d'une visite à
     * l'autre.
     *
     * @return array<int, array{agent: string|null, label: string, total: int, ok: int, fixed: int, last_recorded_at: mixed}>
     */
    public function perAgent(User $user, ?int $siteId = null): array
    {
        $rows = ArticleStatusHistory::query()
            ->forUser($user->id)
            ->when($siteId, fn (Builder $query) => $query->where('wordpress_site_id', $siteId))
            ->selectRaw('agent, count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as ok', [WordpressArticle::AUDIT_OK])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as fixed', [WordpressArticle::AUDIT_FIXED])
            ->selectRaw('max(recorded_at) as last_recorded_at')
            ->groupBy('agent')
            ->get()
            ->keyBy(fn ($row) => $row->agent ?? '');

        $agents = collect(AgentCatalog::all())
            ->map(fn (string $agent) => $this->agentRow($agent, $agent, $rows->get($agent)));

        // Agents retirés de la configuration mais présents dans l'historique.
        $removed = $rows->keys()
            ->filter(fn ($key) => $key !== '' && ! AgentCatalog::has($key))
            ->map(fn ($key) => $this->agentRow($key, $key.' (retiré)', $rows->get($key)));

        $unassigned = $rows->has('')
            ? [$this->agentRow(null, 'Non assigné', $rows->get(''))]
            : [];

        return $agents->concat($removed)->concat($unassigned)->values()->all();
    }

    /**
     * @return array{agent: string|null, label: string, total: int, ok: int, fixed: int, last_recorded_at: mixed}
     */
    protected function agentRow(?string $agent, string $label, ?object $row): array
    {
        return [
            'agent' => $agent,
            'label' => $label,
            'total' => (int) ($row->total ?? 0),
            'ok' => (int) ($row->ok ?? 0),
            'fixed' => (int) ($row->fixed ?? 0),
            'last_recorded_at' => $row->last_recorded_at ?? null,
        ];
    }

    /**
     * Totaux de l'historique, indépendants de l'état courant : ils incluent
     * les sites supprimés.
     *
     * @return array{total: int, ok: int, fixed: int, manual: int, sites: int}
     */
    public function historyTotals(
        User $user,
        ?int $siteId = null,
        ?string $status = null,
        ?string $agent = null,
    ): array {
        $row = $this->historyQuery($user, $siteId, $status, $agent)
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
}
