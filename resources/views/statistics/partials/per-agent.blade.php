{{-- Activité par agent (Admin).

     « Voir » ouvre le détail de l'agent : ses articles en cours et corrigés,
     les erreurs corrigées, les commentaires, et la réassignation. --}}

<div class="ag-card mb-3">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Par agent</h2>
        <span class="ag-hint ms-auto">
            @if($siteFilter) sur le site sélectionné @else tous sites confondus @endif
        </span>
    </div>

    <div class="table-responsive">
        <table class="table ag-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Agent</th>
                    <th scope="col" class="text-end">Corrigés</th>
                    <th scope="col" class="text-end">En cours</th>
                    <th scope="col">Dernière correction</th>
                    <th scope="col">Dernière activité</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($agentRows as $row)
                @php $value = $row['agent']; @endphp

                <tr @class(['ag-row-archived' => $row['fixed'] === 0 && $row['in_progress'] === 0])>
                    <td>
                        <span class="ag-table__title">{{ $row['label'] }}</span>
                    </td>
                    <td class="text-end text-success fw-semibold">{{ $row['fixed'] }}</td>
                    <td class="text-end">
                        @if($row['in_progress'] > 0)
                            <span class="ag-lock ag-lock--other justify-content-end">
                                <span class="ag-lock__dot" aria-hidden="true"></span> {{ $row['in_progress'] }}
                            </span>
                        @else
                            <span class="ag-hint">0</span>
                        @endif
                    </td>
                    <td>
                        <span class="ag-hint">
                            {{ $row['last_recorded_at']
                                ? \Illuminate\Support\Carbon::parse($row['last_recorded_at'])->translatedFormat('d/m/Y')
                                : '—' }}
                        </span>
                    </td>
                    <td>
                        <span class="ag-hint">{{ $row['last_activity']?->diffForHumans() ?? '—' }}</span>
                    </td>
                    <td class="text-end">
                        @if($value === null)
                            <span class="ag-hint">—</span>
                        @else
                            <a href="{{ route('statistics.agent', array_filter(['user' => $value, 'site' => $siteFilter])) }}"
                               class="btn btn-sm btn-outline-primary">Voir</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <p class="ag-hint mb-0 py-3 text-center">
                            Aucun agent. <a href="{{ route('agents.create') }}">Créer un compte agent</a>.
                        </p>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
