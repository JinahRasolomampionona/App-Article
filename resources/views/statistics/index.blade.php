@extends('layouts.app')

@section('title', 'Statistiques')
@section('heading', 'Statistiques')
@section('subheading', 'Vue Admin : état des articles, activité des agents et historique des corrections.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <span class="active">Statistiques</span>
@endsection

@section('content')
@if($overview['articles'] === 0 && $historyTotals['total'] === 0)
    <div class="ag-card">
        <div class="ag-empty">
            <div class="ag-empty__icon"><i class="bi bi-bar-chart-line" aria-hidden="true"></i></div>
            <p class="ag-empty__title">Aucune statistique pour l’instant</p>
            <p class="ag-empty__text">
                Connectez un site WordPress et lancez une synchronisation : l’état des articles
                et l’historique des corrections apparaîtront ici.
            </p>
            <a href="{{ route('sites.create') }}" class="btn btn-primary btn-sm mt-3">Connecter un site</a>

        </div>
    </div>
@else
    {{-- Vue d'ensemble : la phrase que l'utilisateur attend, en clair. --}}
    <div class="ag-card mb-3">
        <div class="ag-card__body">
            <p class="ag-headline mb-3">
                <strong>{{ number_format($overview['articles'], 0, ',', ' ') }}</strong> article(s) suivis, dont
                <strong class="text-success">{{ number_format($overview['corrected'], 0, ',', ' ') }}</strong>
                OK / Corrigés
                @if($overview['needs_fix'] > 0)
                    et
                    <strong class="text-danger">{{ number_format($overview['needs_fix'], 0, ',', ' ') }}</strong>
                    à corriger
                @endif
                @if($overview['pending'] > 0)
                    · {{ $overview['pending'] }} en attente d’audit
                @endif
            </p>

            <div class="ag-progress" role="img"
                 aria-label="{{ $overview['rate'] }} % des articles sont OK ou corrigés">
                <span class="ag-progress__bar" style="width: {{ $overview['rate'] }}%"></span>
            </div>
            <p class="ag-hint mt-1 mb-3">{{ $overview['rate'] }} % des articles suivis sont conformes.</p>

            <div class="ag-stat-row">
                <div class="ag-stat">
                    <p class="ag-stat__label"><i class="bi bi-files" aria-hidden="true"></i> Total articles</p>
                    <p class="ag-stat__value">{{ number_format($overview['articles'], 0, ',', ' ') }}</p>
                    <p class="ag-stat__hint">{{ count($sites) }} site(s) connecté(s)</p>
                </div>

                <div class="ag-stat ag-stat--success">
                    <p class="ag-stat__label"><i class="bi bi-check2-circle" aria-hidden="true"></i> OK / Corrigés</p>
                    <p class="ag-stat__value">{{ number_format($overview['corrected'], 0, ',', ' ') }}</p>
                    <p class="ag-stat__hint">
                        {{ $overview['ok'] }} sans erreur · {{ $overview['fixed'] }} corrigé(s)
                    </p>
                </div>

                <div class="ag-stat ag-stat--danger">
                    <p class="ag-stat__label">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Non corrigés
                    </p>
                    <p class="ag-stat__value">{{ number_format($overview['needs_fix'], 0, ',', ' ') }}</p>
                    <p class="ag-stat__hint">articles à corriger</p>
                </div>

                <div class="ag-stat">
                    <p class="ag-stat__label"><i class="bi bi-person-workspace" aria-hidden="true"></i> En cours</p>
                    <p class="ag-stat__value">{{ number_format($overview['in_progress'], 0, ',', ' ') }}</p>
                    <p class="ag-stat__hint">articles pris par un agent</p>
                </div>
            </div>
        </div>
    </div>

    @include('statistics._filters')

    @include('statistics.partials.per-agent')
    @include('statistics.partials.in-progress')
    @include('statistics.partials.per-site')
    @include('statistics.partials.activity')
    @include('statistics.partials.history')
    @include('statistics.partials.pending')
@endif
@endsection
