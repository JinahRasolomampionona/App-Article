@extends('layouts.app')

@section('title', 'Mes statistiques')
@section('heading', 'Mes statistiques')
@section('subheading', 'Vos corrections, vos articles en cours et votre activité.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <span class="active">Mes statistiques</span>
@endsection

@section('content')
    {{-- Uniquement les chiffres de l'agent connecté : aucune donnée d'un autre
         agent n'est calculée pour cette page. --}}
    <div class="ag-card mb-3">
        <div class="ag-card__body">
            <p class="ag-headline mb-3">
                <strong class="text-success">{{ number_format($me['corrected'], 0, ',', ' ') }}</strong>
                article(s) corrigé(s) à votre actif
                @if($me['in_progress'] > 0)
                    · <strong>{{ $me['in_progress'] }}</strong> en cours
                @endif
            </p>

            <div class="ag-stat-row ag-stat-row--pair">
                <div class="ag-stat ag-stat--success">
                    <p class="ag-stat__label"><i class="bi bi-check2-circle" aria-hidden="true"></i> Articles corrigés</p>
                    <p class="ag-stat__value">{{ number_format($me['corrected'], 0, ',', ' ') }}</p>
                    <p class="ag-stat__hint">{{ $me['fixed'] }} corrigé(s) · {{ $me['ok'] }} OK</p>
                </div>

                {{-- Articles pris encore « À corriger » : passer l'un d'eux en
                     « Corrigé » le fait passer dans « Articles corrigés ». --}}
                <div class="ag-stat">
                    <p class="ag-stat__label"><i class="bi bi-person-workspace" aria-hidden="true"></i> En cours</p>
                    <p class="ag-stat__value">{{ $me['in_progress'] }}</p>
                    <p class="ag-stat__hint">articles pris, encore à corriger</p>
                </div>
            </div>
        </div>
    </div>

    @include('statistics._filters')

    @include('statistics.partials.in-progress')
    @include('statistics.partials.activity')
    @include('statistics.partials.history')
    @include('statistics.partials.pending')
@endsection
