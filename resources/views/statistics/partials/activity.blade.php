{{-- Rythme des corrections, par jour, semaine ou mois. --}}

@php
    $periods = [
        'day' => ['Jour', '14 derniers jours', 'aujourd’hui'],
        'week' => ['Semaine', '12 dernières semaines', 'cette semaine'],
        'month' => ['Mois', '12 derniers mois', 'ce mois-ci'],
    ];
@endphp

<div class="ag-card mb-3" id="ag-corrections"
     data-url="{{ route('statistics.series', ['site' => $siteFilter]) }}"
     data-granularity="{{ $granularity }}"
     data-series="{{ json_encode($series, JSON_THROW_ON_ERROR) }}">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Articles corrigés dans le temps</h2>

        <div class="ag-tabs ms-auto" role="tablist" aria-label="Période des statistiques">
            @foreach($periods as $value => $period)
                <button type="button" role="tab" data-corrections-tab="{{ $value }}"
                        class="{{ $granularity === $value ? 'is-active' : '' }}"
                        aria-selected="{{ $granularity === $value ? 'true' : 'false' }}">
                    {{ $period[0] }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="ag-card__body">
        <div class="ag-stat-row mb-3">
            @foreach($periods as $value => $period)
                @php $stats = $summary[$value] ?? ['articles' => 0, 'fixed' => 0, 'trend' => null]; @endphp

                <div class="ag-stat">
                    <p class="ag-stat__label">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Passés au vert {{ $period[2] }}
                    </p>
                    <p class="ag-stat__value">{{ $stats['articles'] }}</p>
                    <p class="ag-stat__hint">
                        dont {{ $stats['fixed'] }} corrigé(s)
                        @if($stats['trend'] !== null)
                            <span class="ag-trend ag-trend--{{ $stats['trend'] >= 0 ? 'up' : 'down' }}">
                                <i class="bi bi-arrow-{{ $stats['trend'] >= 0 ? 'up' : 'down' }}-right"
                                   aria-hidden="true"></i>{{ abs($stats['trend']) }}&nbsp;%
                                <span class="visually-hidden">par rapport à la période précédente</span>
                            </span>
                        @endif
                    </p>
                </div>
            @endforeach
        </div>

        <div data-corrections-chart aria-busy="false"></div>

        <p class="ag-hint mt-2 mb-0">
            <span data-corrections-period>{{ $periods[$granularity][1] }}</span> ·
            un article est compté à chaque passage au statut « OK » ou « Corrigé ».
        </p>
    </div>
</div>
