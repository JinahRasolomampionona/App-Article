@extends('layouts.app')

@section('title', 'Modifier le site')
@section('heading', $site->name)
@section('subheading', 'Paramètres de connexion du site WordPress.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <a href="{{ route('sites.index') }}">Sites WordPress</a> <span class="mx-1">/</span>
    <span class="active">{{ $site->name }}</span>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-7">
        <div class="ag-card">
            <div class="ag-card__body">
                <form method="POST" action="{{ route('sites.update', $site) }}" novalidate>
                    @csrf
                    @method('PUT')
                    @include('sites._form', [
                        'site' => $site,
                        // Site partagé : nom et adresse communs aux autres comptes.
                        'lockName' => $sharedWith->isNotEmpty() && ! auth()->user()->isAdmin(),
                        'lockUrl' => $sharedWith->isNotEmpty(),
                    ])

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                        <a href="{{ route('sites.index') }}" class="btn btn-outline-secondary">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="ag-card">
            <div class="ag-card__header">
                <h2 class="ag-card__title">État de la connexion</h2>
            </div>
            <div class="ag-card__body">
                <p class="mb-2">
                    <span class="ag-badge ag-badge--{{ $site->statusVariant() }}">{{ $site->statusLabel() }}</span>
                </p>
                @if($site->connection_message)
                    <p class="small ag-muted">{{ $site->connection_message }}</p>
                @endif
                <dl class="row small mb-0">
                    <dt class="col-5 fw-normal ag-muted">Dernier test</dt>
                    <dd class="col-7">{{ $site->last_checked_at?->diffForHumans() ?? '—' }}</dd>
                    <dt class="col-5 fw-normal ag-muted">Dernière synchro</dt>
                    <dd class="col-7">{{ $site->last_sync_at?->diffForHumans() ?? '—' }}</dd>
                    <dt class="col-5 fw-normal ag-muted">Articles</dt>
                    <dd class="col-7">{{ $site->articles()->count() }}</dd>
                </dl>
                @if($sharedWith->isNotEmpty())
                    <p class="ag-hint mt-3 mb-0">
                        <i class="bi bi-people me-1" aria-hidden="true"></i>
                        Aussi connecté par {{ $sharedWith->pluck('user.name')->filter()->join(', ', ' et ') }},
                        avec leurs propres identifiants. Les articles sont partagés.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
