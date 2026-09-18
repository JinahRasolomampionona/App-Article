@extends('layouts.app')

@section('title', 'Articles')
@section('heading', 'Articles')
@section('subheading', $site ? 'Gestion et audit des articles de '.$site->name : 'Sélectionnez un site WordPress pour commencer.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <span class="active">Articles</span>
@endsection

@section('actions')
    @if($site)
        <button type="button" class="btn btn-sm btn-outline-secondary"
                data-sync-url="{{ route('sites.sync', $site) }}" id="ag-sync-current">
            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Synchroniser
        </button>
    @endif
@endsection

@section('content')
@if(! $site)
    <div class="ag-card">
        <div class="ag-empty">
            <div class="ag-empty__icon"><i class="bi bi-file-text" aria-hidden="true"></i></div>
            <p class="ag-empty__title">Aucun site sélectionné</p>
            <p class="ag-empty__text">Connectez un site WordPress pour afficher ses articles.</p>
            <a href="{{ route('sites.create') }}" class="btn btn-primary btn-sm mt-3">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site
            </a>
        </div>
    </div>
@else
<div id="ag-articles" data-url="{{ route('articles.index') }}">

    {{-- Filtres --}}
    <form id="ag-filters" class="ag-card mb-3" method="GET" action="{{ route('articles.index') }}">
        <div class="ag-card__body">
            <div class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label for="ag-search" class="form-label">Rechercher</label>
                    <div class="position-relative">
                        <i class="bi bi-search position-absolute ag-muted"
                           style="left:.7rem;top:50%;transform:translateY(-50%);font-size:.85rem;" aria-hidden="true"></i>
                        <input type="search" id="ag-search" name="search" value="{{ $filters['search'] }}"
                               class="form-control ps-4" placeholder="Titre, slug, URL ou ID WordPress"
                               autocomplete="off">
                    </div>
                </div>

                <div class="col-6 col-lg-3">
                    <label for="ag-status" class="form-label">Statut</label>
                    <select id="ag-status" name="status" class="form-select">
                        <option value="" @selected($filters['status'] === 'all')>Tous</option>
                        <option value="needs_fix" @selected($filters['status'] === 'needs_fix')>À corriger</option>
                        <option value="ok" @selected($filters['status'] === 'ok')>Sans erreur</option>
                        <option value="pending" @selected($filters['status'] === 'pending')>En attente d’audit</option>
                    </select>
                </div>

                <div class="col-6 col-lg-2">
                    <label for="ag-per-page" class="form-label">Par page</label>
                    <select id="ag-per-page" name="per_page" class="form-select">
                        @foreach([10, 20, 50, 100] as $size)
                            <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-lg-2 d-grid">
                    <button type="button" class="btn btn-outline-secondary" id="ag-filters-reset">
                        Réinitialiser
                    </button>
                </div>
            </div>

            @if($categories->isNotEmpty())
                <hr class="my-3">

                <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
                    <span class="form-label mb-0">Catégories</span>

                    <div class="d-flex gap-3">
                        <label class="form-check form-check-inline mb-0 small">
                            <input class="form-check-input" type="radio" name="mode" value="any"
                                   @checked($filters['mode'] === 'any')>
                            Au moins une
                        </label>
                        <label class="form-check form-check-inline mb-0 small">
                            <input class="form-check-input" type="radio" name="mode" value="all"
                                   @checked($filters['mode'] === 'all')>
                            Toutes
                        </label>
                    </div>
                </div>

                <div class="ag-filter-list" role="group" aria-label="Filtrer par catégorie">
                    @foreach($categories as $category)
                        <label class="ag-filter-check">
                            <input type="checkbox" class="form-check-input" name="categories[]"
                                   value="{{ $category->id }}"
                                   @checked(in_array($category->id, $filters['categories'], true))>
                            {{ $category->name }}
                            <span class="ag-muted">{{ $category->posts_count }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>
    </form>

    {{-- Barre d'actions en masse --}}
    <div class="ag-card mb-3" id="ag-bulk" hidden>
        <div class="ag-card__body d-flex flex-wrap align-items-center gap-2 py-2">
            <span class="small"><strong id="ag-bulk-count">0</strong> article(s) sélectionné(s)</span>
            <div class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-sm btn-primary" id="ag-bulk-audit"
                        data-url="{{ route('articles.bulk-audit') }}">
                    <i class="bi bi-clipboard-check me-1" aria-hidden="true"></i> Lancer un audit
                </button>
            </div>
        </div>
    </div>

    {{-- Tableau --}}
    <div class="ag-card" id="ag-table-wrapper">
        <div class="ag-card__header">
            <h2 class="ag-card__title">Articles</h2>
            <span class="ag-hint ms-auto" id="ag-meta" aria-live="polite">
                @if($articles->total())
                    {{ $articles->firstItem() }}–{{ $articles->lastItem() }} sur {{ $articles->total() }} article(s)
                @else
                    Aucun article
                @endif
            </span>
        </div>

        <div class="table-responsive">
            <table class="table ag-table align-middle">
                <thead>
                    <tr>
                        <th scope="col" style="width:2.5rem;">
                            <input type="checkbox" class="form-check-input" id="ag-select-all"
                                   aria-label="Tout sélectionner">
                        </th>
                        <th scope="col">Titre</th>
                        <th scope="col">Catégories</th>
                        <th scope="col">URL</th>
                        <th scope="col">Remarques</th>
                        <th scope="col">Statut</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="ag-articles-body">
                    @include('articles.partials.rows', [
                        'articles' => $articles,
                        'site' => $site,
                        'siteHasArticles' => $siteHasArticles,
                    ])
                </tbody>
            </table>
        </div>

        <div class="ag-card__body border-top" id="ag-pagination">
            @include('articles.partials.pagination', ['articles' => $articles])
        </div>
    </div>
</div>

{{-- Modal : détail des problèmes --}}
<div class="modal fade" id="ag-issues-modal" tabindex="-1" aria-labelledby="ag-issues-heading" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title h6 mb-0" id="ag-issues-heading">Problèmes détectés</h2>
                    <p class="ag-hint mb-0" data-issues-title></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" data-issues-list></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fermer</button>
                <a href="#" class="btn btn-sm btn-primary" data-issues-edit>Corriger l’article</a>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
