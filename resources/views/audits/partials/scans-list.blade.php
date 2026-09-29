{{-- Scans d'un article, du plus récent au plus ancien. --}}
@if($scans->isEmpty())
    <p class="ag-muted mb-0">Cet article n’a encore jamais été scanné.</p>
@else
    <div class="table-responsive">
        <table class="table ag-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Date du scan</th>
                    <th scope="col">Origine</th>
                    <th scope="col">Résultat</th>
                    <th scope="col" class="text-end">Résolus</th>
                </tr>
            </thead>
            <tbody>
            @foreach($scans as $scan)
                <tr>
                    <td>
                        <span class="fw-semibold">{{ $scan->created_at->translatedFormat('d/m/Y à H:i') }}</span>
                        @if($loop->first)
                            <span class="ag-badge ag-badge--primary ms-1">Plus récent</span>
                        @elseif($loop->last && $scans->count() === $total)
                            <span class="ag-badge ag-badge--muted ms-1">Premier scan</span>
                        @endif
                        <span class="ag-hint d-block">{{ $scan->created_at->diffForHumans() }}</span>
                    </td>
                    <td><span class="ag-chip">{{ $scan->triggerLabel() }}</span></td>
                    <td>
                        <span class="ag-badge ag-badge--{{ $scan->resultVariant() }}">{{ $scan->resultLabel() }}</span>
                    </td>
                    <td class="text-end">
                        @if($scan->resolved_count > 0)
                            <span class="text-success fw-semibold">{{ $scan->resolved_count }}</span>
                        @else
                            <span class="ag-hint">0</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if($total > $scans->count())
        <p class="ag-hint mt-2 mb-0">{{ $scans->count() }} scans les plus récents affichés sur {{ $total }}.</p>
    @endif
@endif
