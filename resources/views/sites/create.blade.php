@extends('layouts.app')

@section('title', 'Connecter un site')
@section('heading', 'Connecter un site WordPress')
@section('subheading', 'La connexion est testée immédiatement, puis les catégories et articles sont récupérés.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <a href="{{ route('sites.index') }}">Sites WordPress</a> <span class="mx-1">/</span>
    <span class="active">Nouveau</span>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-7">
        <div class="ag-card">
            <div class="ag-card__body">
                <form method="POST" action="{{ route('sites.store') }}" novalidate>
                    @csrf
                    @include('sites._form', ['site' => null])

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            Connecter et synchroniser
                        </button>
                        <a href="{{ route('sites.index') }}" class="btn btn-outline-secondary">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="ag-card">
            <div class="ag-card__header">
                <h2 class="ag-card__title">Ce qui se passe ensuite</h2>
            </div>
            <div class="ag-card__body small">
                <ol class="ps-3 mb-0 ag-muted">
                    <li class="mb-2">L’adresse est normalisée et vérifiée (protocole, domaine, destination).</li>
                    <li class="mb-2">La connexion à <span class="ag-mono">/wp-json/wp/v2</span> est testée.</li>
                    <li class="mb-2">Les catégories puis les articles sont récupérés, page par page.</li>
                    <li class="mb-2">Un premier audit est lancé en arrière-plan.</li>
                    <li>Les remarques apparaissent dans le tableau des articles.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
@endsection
