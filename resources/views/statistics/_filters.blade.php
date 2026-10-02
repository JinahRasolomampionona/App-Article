{{-- Filtres des statistiques : le site, et sur la page d'un agent le jour de
     correction. --}}
@php
    $action = $filterAction ?? route('statistics.index');
    $withDates = $withDates ?? false;
    $day = $withDates ? $filter->from?->toDateString() : null;
    $active = $siteFilter || $day;
@endphp

<form method="GET" action="{{ $action }}" class="ag-card mb-3">
    <div class="ag-card__body">
        <div class="row g-2 align-items-end">
            <div class="col-sm-6 {{ $withDates ? 'col-lg-3' : 'col-lg-4' }}">
                <label for="ag-stats-site" class="form-label small mb-1">Site</label>
                <select id="ag-stats-site" name="site" class="form-select form-select-sm">
                    <option value="">Tous les sites</option>
                    @foreach($userSites as $option)
                        <option value="{{ $option->id }}" @selected($siteFilter === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>

            @if($withDates)
                {{-- Jour de correction : seuls les articles déclarés corrigés
                     ce jour-là sont listés. --}}
                <div class="col-sm-6 col-lg-3">
                    <label for="ag-stats-date" class="form-label small mb-1">Date de correction</label>
                    <input type="date" id="ag-stats-date" name="date" value="{{ $day }}"
                           max="{{ now()->toDateString() }}" class="form-control form-control-sm">
                </div>
            @endif

            <div class="col-sm-4 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">Filtrer</button>
                @if($active)
                    <a href="{{ $action }}" class="btn btn-sm btn-outline-secondary"
                       aria-label="Réinitialiser les filtres" title="Réinitialiser">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                @endif
            </div>
        </div>

        @if($withDates && $day)
            <p class="ag-hint mt-2 mb-0">
                <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>
                Articles corrigés le {{ $filter->from->translatedFormat('l d/m/Y') }}
                — les articles en cours ne sont pas affichés.
            </p>
        @endif
    </div>
</form>
