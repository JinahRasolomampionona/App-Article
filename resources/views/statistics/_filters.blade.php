{{-- Filtre des statistiques : le site. --}}
@php $action = $filterAction ?? route('statistics.index'); @endphp

<form method="GET" action="{{ $action }}" class="ag-card mb-3">
    <div class="ag-card__body">
        <div class="row g-2 align-items-end">
            <div class="col-sm-8 col-lg-4">
                <label for="ag-stats-site" class="form-label small mb-1">Site</label>
                <select id="ag-stats-site" name="site" class="form-select form-select-sm">
                    <option value="">Tous les sites</option>
                    @foreach($userSites as $option)
                        <option value="{{ $option->id }}" @selected($siteFilter === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-4 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">Filtrer</button>
                @if($siteFilter)
                    <a href="{{ $action }}" class="btn btn-sm btn-outline-secondary"
                       aria-label="Réinitialiser le filtre" title="Réinitialiser">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                @endif
            </div>
        </div>
    </div>
</form>
