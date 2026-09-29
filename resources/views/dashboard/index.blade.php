@extends('layouts.app')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('subheading', $site ? 'Vue d’ensemble de '.$site->name : 'Vue d’ensemble de vos contenus WordPress')

@section('breadcrumb')
    <span class="active">Dashboard</span>
@endsection

@section('actions')
    @if($site)
        <a href="{{ route('articles.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-file-text me-1" aria-hidden="true"></i> Voir les articles
        </a>
        <button type="button" class="btn btn-sm btn-primary" data-sync-url="{{ route('sites.sync', $site) }}" id="ag-sync-current">
            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Synchroniser
        </button>
    @endif
@endsection

@section('content')
    @if(! $site)
        <div class="ag-card">
            <div class="ag-empty">
                <div class="ag-empty__icon"><i class="bi bi-globe2" aria-hidden="true"></i></div>
                <p class="ag-empty__title">Aucun site WordPress connecté</p>
                <p class="ag-empty__text">
                    Connectez un site pour récupérer ses catégories et ses articles, puis lancer
                    un premier audit de contenu.
                </p>
                <a href="{{ route('sites.create') }}" class="btn btn-primary btn-sm mt-3">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site WordPress
                </a>
            </div>
        </div>
    @else
        @include('partials.status-cards', ['cards' => $cards, 'linkSite' => $site->id])

        <p class="ag-hint mb-0">
            Dernière synchronisation de {{ $site->name }} :
            {{ $site->last_sync_at?->diffForHumans() ?? 'jamais' }}.
        </p>
    @endif
@endsection
