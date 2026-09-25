{{-- Historique des corrections : nom du site et date, conservés même après la
     suppression du site. --}}

<div class="ag-card mb-3">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Historique des corrections</h2>

        <div class="ag-tabs ms-auto" role="tablist" aria-label="Filtre de statut">
            @foreach(['' => 'Tous', 'ok' => 'OK', 'fixed' => 'Corrigés'] as $value => $label)
                <a href="{{ route('statistics.index', $filter->query(['status' => $value ?: null])) }}"
                   role="tab" class="text-decoration-none {{ (string) $statusFilter === (string) $value ? 'is-active' : '' }}"
                   aria-selected="{{ (string) $statusFilter === (string) $value ? 'true' : 'false' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="ag-card__body border-bottom">
        <p class="ag-hint mb-0">
            @if($agentFilter === 'none')
                <strong>Corrections sans agent</strong> ·
            @elseif($agentFilter && ! empty($agentFilterLabel))
                <strong>Corrections de {{ $agentFilterLabel }}</strong> ·
            @endif
            {{ number_format($historyTotals['total'], 0, ',', ' ') }} entrée(s) sur
            {{ $historyTotals['sites'] }} site(s) ·
            {{ $historyTotals['ok'] }} OK · {{ $historyTotals['fixed'] }} corrigé(s).
        </p>
    </div>

    <div class="table-responsive">
        <table class="table ag-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Site</th>
                    <th scope="col">Article</th>
                    <th scope="col">Agent</th>
                    <th scope="col">Statut</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($history as $entry)
                <tr>
                    <td>
                        <span class="ag-table__title">{{ $entry->recorded_at?->translatedFormat('d/m/Y') }}</span>
                        <span class="ag-table__url">{{ $entry->recorded_at?->translatedFormat('H:i') }}</span>
                    </td>
                    <td>
                        <span class="ag-table__title text-truncate">{{ $entry->site_name }}</span>
                        @if($entry->siteWasDeleted())
                            <span class="ag-table__url">
                                <span class="ag-badge ag-badge--muted">Site supprimé</span>
                            </span>
                        @else
                            <span class="ag-table__url">{{ $entry->site_url }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="ag-table__title text-truncate">{{ $entry->article_title }}</span>
                        @if($entry->wp_id)
                            <span class="ag-table__url">#{{ $entry->wp_id }}</span>
                        @endif
                    </td>
                    <td>
                        @if($entry->agent)
                            <span class="ag-chip">{{ $entry->agent }}</span>
                            @if(! $entry->agent_user_id)
                                <span class="ag-hint d-block">sans compte</span>
                            @endif
                        @else
                            <span class="ag-hint">Non assigné</span>
                        @endif
                    </td>
                    <td>
                        <span class="ag-badge ag-badge--{{ $entry->statusVariant() }}">
                            {{ $entry->statusLabel() }}
                        </span>
                        @if($entry->issues_resolved > 0)
                            <span class="ag-hint d-block">{{ $entry->issues_resolved }} problème(s) résolu(s)</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @if($entry->wordpress_article_id)
                            <a href="{{ route('articles.edit', $entry->wordpress_article_id) }}"
                               class="btn btn-sm btn-outline-secondary">Ouvrir</a>
                        @elseif($entry->article_url)
                            <a href="{{ $entry->article_url }}" target="_blank" rel="noopener noreferrer"
                               class="btn btn-sm btn-outline-secondary">Voir</a>
                        @else
                            <span class="ag-hint">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="ag-empty py-5">
                            <div class="ag-empty__icon"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
                            <p class="ag-empty__title">Aucune correction enregistrée</p>
                            <p class="ag-empty__text">
                                Les articles apparaissent ici dès qu’un audit les confirme conformes,
                                ou lorsqu’ils sont déclarés corrigés depuis le tableau.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($history->hasPages())
        <div class="ag-card__body border-top">
            {{ $history->links() }}
        </div>
    @endif
</div>
