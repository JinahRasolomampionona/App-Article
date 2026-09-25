{{-- Filtres des statistiques. Un Agent n'a pas de filtre d'agent : ses
     statistiques sont toujours les siennes (contrôlé côté serveur). --}}
@php $isAdmin = auth()->user()->isAdmin(); @endphp

<form method="GET" action="{{ route('statistics.index') }}" class="ag-card mb-3">
    <div class="ag-card__body">
        <div class="row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label for="ag-stats-site" class="form-label small mb-1">Site</label>
                <select id="ag-stats-site" name="site" class="form-select form-select-sm">
                    <option value="">Tous les sites</option>
                    @foreach($userSites as $option)
                        <option value="{{ $option->id }}" @selected($siteFilter === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>

            @if($isAdmin)
                <div class="col-sm-6 col-lg-2">
                    <label for="ag-stats-agent" class="form-label small mb-1">Agent</label>
                    <select id="ag-stats-agent" name="agent" class="form-select form-select-sm">
                        <option value="">Tous les agents</option>
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" @selected($agentFilter === $agent->id)>
                                {{ $agent->name }}@unless($agent->is_active) (désactivé)@endunless
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="col-6 col-lg-2">
                <label for="ag-stats-from" class="form-label small mb-1">Du</label>
                <input type="date" id="ag-stats-from" name="from" class="form-control form-control-sm"
                       value="{{ $filter->from?->toDateString() }}">
            </div>

            <div class="col-6 col-lg-2">
                <label for="ag-stats-to" class="form-label small mb-1">Au</label>
                <input type="date" id="ag-stats-to" name="to" class="form-control form-control-sm"
                       value="{{ $filter->to?->toDateString() }}">
            </div>

            <div class="col-sm-6 col-lg-1">
                <label for="ag-stats-status" class="form-label small mb-1">Statut</label>
                <select id="ag-stats-status" name="status" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <option value="ok" @selected($statusFilter === 'ok')>OK</option>
                    <option value="fixed" @selected($statusFilter === 'fixed')>Corrigés</option>
                </select>
            </div>

            <div class="col-sm-6 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">Filtrer</button>
                @if($siteFilter || $agentFilter || $statusFilter || $filter->from || $filter->to)
                    <a href="{{ route('statistics.index') }}" class="btn btn-sm btn-outline-secondary"
                       aria-label="Réinitialiser les filtres" title="Réinitialiser">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                @endif
            </div>
        </div>
        <p class="ag-hint mt-2 mb-0">
            La période et le statut s’appliquent à l’historique des corrections et aux compteurs par agent.
        </p>
    </div>
</form>
