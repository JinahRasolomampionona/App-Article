{{-- Répartition par site, sur le principe des quatre cartes du haut (mêmes
     calculs) : Articles, Non corrigés (À corriger), Non vérifiés (À vérifier),
     Corrigés (déclarés corrigés par un agent). Simple tableau de lecture :
     rien n'y est cliquable.

     Un site supprimé n'a plus d'articles : seule sa ligne d'historique
     subsiste, signalée comme archivée. --}}

<div class="ag-card mb-3">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Par site</h2>
        <span class="ag-hint ms-auto">
            {{ count($sites) }} connecté(s)@if(count($archivedSites)) · {{ count($archivedSites) }} archivé(s)@endif
        </span>

        @if(count($archivedSites))
            <form method="POST" action="{{ route('statistics.purge-archived') }}" class="ms-2"
                  data-confirm="Retirer définitivement les {{ count($archivedSites) }} site(s) supprimé(s) et leurs corrections des statistiques ?">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash me-1" aria-hidden="true"></i> Supprimer les sites supprimés
                </button>
            </form>
        @endif
    </div>

    <div class="table-responsive">
        <table class="table ag-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Site</th>
                    <th scope="col" class="text-end">Articles</th>
                    <th scope="col" class="text-end">Non corrigés</th>
                    <th scope="col" class="text-end">Non vérifiés</th>
                    <th scope="col" class="text-end">Corrigés</th>
                    <th scope="col">Dernière correction</th>
                </tr>
            </thead>
            <tbody>
            @forelse($sites as $row)
                <tr>
                    <td>
                        <span class="ag-table__title text-truncate">{{ $row['name'] }}</span>
                        <span class="ag-table__url">{{ $row['url'] }}</span>
                    </td>
                    <td class="text-end">
                        {{ number_format($row['articles'], 0, ',', ' ') }}
                        @if($row['pending'] > 0)
                            <span class="ag-hint d-block">{{ $row['pending'] }} en attente d’analyse</span>
                        @endif
                    </td>
                    <td class="text-end {{ $row['needs_fix'] > 0 ? 'text-danger fw-semibold' : 'ag-muted' }}">
                        {{ number_format($row['needs_fix'], 0, ',', ' ') }}
                    </td>
                    <td class="text-end {{ $row['to_review'] > 0 ? 'text-info fw-semibold' : 'ag-muted' }}">
                        {{ number_format($row['to_review'], 0, ',', ' ') }}
                    </td>
                    <td class="text-end {{ $row['fixed'] > 0 ? 'text-success fw-semibold' : 'ag-muted' }}">
                        {{ number_format($row['fixed'], 0, ',', ' ') }}
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
                    <td class="text-end ag-muted">—</td>
                    <td class="text-end ag-muted">—</td>
                    <td class="text-end fw-semibold">{{ $row['corrected'] }}</td>
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
