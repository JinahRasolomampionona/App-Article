{{-- Répartition par site, sites supprimés compris.

     Un site supprimé n'a plus d'articles : seule sa ligne d'historique
     subsiste, signalée comme archivée. --}}

<div class="ag-card mb-3">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Par site</h2>
        <span class="ag-hint ms-auto">
            {{ count($sites) }} connecté(s)@if(count($archivedSites)) · {{ count($archivedSites) }} archivé(s)@endif
        </span>
    </div>

    <div class="table-responsive">
        <table class="table ag-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Site</th>
                    <th scope="col" class="text-end">Articles</th>
                    <th scope="col" class="text-end">OK / Corrigés</th>
                    <th scope="col" class="text-end">Non corrigés</th>
                    <th scope="col">Conformité</th>
                    <th scope="col">Dernière correction</th>
                </tr>
            </thead>
            <tbody>
            @forelse($sites as $row)
                <tr>
                    <td>
                        <a href="{{ route('statistics.index', ['site' => $row['site']->id]) }}"
                           class="ag-table__title text-truncate">{{ $row['name'] }}</a>
                        <span class="ag-table__url">{{ $row['url'] }}</span>
                    </td>
                    <td class="text-end">{{ number_format($row['articles'], 0, ',', ' ') }}</td>
                    <td class="text-end text-success fw-semibold">{{ $row['corrected'] }}</td>
                    <td class="text-end {{ $row['needs_fix'] > 0 ? 'text-danger fw-semibold' : 'ag-muted' }}">
                        {{ $row['needs_fix'] }}
                    </td>
                    <td style="min-width: 8rem;">
                        <div class="ag-progress ag-progress--sm" role="img"
                             aria-label="{{ $row['rate'] }} % conformes">
                            <span class="ag-progress__bar" style="width: {{ $row['rate'] }}%"></span>
                        </div>
                        <span class="ag-hint">{{ $row['rate'] }} %</span>
                    </td>
                    <td>
                        <span class="ag-hint">
                            {{ $row['last_corrected_at']
                                ? \Illuminate\Support\Carbon::parse($row['last_corrected_at'])->translatedFormat('d/m/Y')
                                : '—' }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <p class="ag-hint mb-0 py-3 text-center">Aucun site connecté.</p>
                    </td>
                </tr>
            @endforelse

            {{-- Sites supprimés : conservés pour l'historique. --}}
            @foreach($archivedSites as $row)
                <tr class="ag-row-archived">
                    <td>
                        <span class="ag-table__title text-truncate">{{ $row['name'] }}</span>
                        <span class="ag-table__url">
                            <span class="ag-badge ag-badge--muted">Site supprimé</span>
                        </span>
                    </td>
                    <td class="text-end ag-muted">—</td>
                    <td class="text-end fw-semibold">{{ $row['corrected'] }}</td>
                    <td class="text-end ag-muted">—</td>
                    <td><span class="ag-hint">Archivé</span></td>
                    <td>
                        <span class="ag-hint">
                            {{ \Illuminate\Support\Carbon::parse($row['last_corrected_at'])->translatedFormat('d/m/Y') }}
                        </span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
