{{-- Corrections par agent.

     Chaque ligne mène à l'historique filtré : c'est le chemin attendu pour
     « voir les corrections faites par Daniella ». --}}

<div class="ag-card mb-3">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Par agent</h2>
        <span class="ag-hint ms-auto">
            @if($siteFilter)
                sur le site sélectionné
            @else
                tous sites confondus
            @endif
        </span>
    </div>

    <div class="table-responsive">
        <table class="table ag-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Agent</th>
                    <th scope="col" class="text-end">Corrections</th>
                    <th scope="col" class="text-end">OK</th>
                    <th scope="col" class="text-end">Corrigés</th>
                    <th scope="col">Dernière</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($agentRows as $row)
                @php
                    $value = $row['agent'] ?? 'none';
                    $isActive = (string) $agentFilter === (string) $value;
                @endphp

                <tr @class(['ag-row-archived' => $row['total'] === 0])>
                    <td>
                        <span class="ag-table__title">{{ $row['label'] }}</span>
                        @if($isActive)
                            <span class="ag-table__url">
                                <span class="ag-badge ag-badge--primary">Filtre actif</span>
                            </span>
                        @endif
                    </td>
                    <td class="text-end fw-semibold">{{ $row['total'] }}</td>
                    <td class="text-end ag-muted">{{ $row['ok'] }}</td>
                    <td class="text-end text-success fw-semibold">{{ $row['fixed'] }}</td>
                    <td>
                        <span class="ag-hint">
                            {{ $row['last_recorded_at']
                                ? \Illuminate\Support\Carbon::parse($row['last_recorded_at'])->translatedFormat('d/m/Y')
                                : '—' }}
                        </span>
                    </td>
                    <td class="text-end">
                        @if($isActive)
                            <a href="{{ route('statistics.index', array_filter(['site' => $siteFilter, 'status' => $statusFilter])) }}"
                               class="btn btn-sm btn-outline-secondary">Retirer le filtre</a>
                        @else
                            <a href="{{ route('statistics.index', array_filter([
                                    'site' => $siteFilter,
                                    'status' => $statusFilter,
                                    'agent' => $value,
                                ])) }}"
                               class="btn btn-sm btn-outline-primary"
                               @disabled($row['total'] === 0)>Voir</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <p class="ag-hint mb-0 py-3 text-center">Aucun agent configuré.</p>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
