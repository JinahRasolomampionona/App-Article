@extends('layouts.app')

@section('title', 'Sites WordPress')
@section('heading', 'Sites WordPress')
@section('subheading', 'Connectez, testez et synchronisez vos sites.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <span class="active">Sites WordPress</span>
@endsection

@section('actions')
    <a href="{{ route('sites.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site
    </a>
@endsection

@section('content')
<div id="ag-sites">
    @forelse($sites as $site)
        <div class="ag-card mb-3" data-site-row
             data-status-url="{{ route('sites.sync-status', $site) }}">
            <div class="ag-card__body">
                <div class="d-flex flex-wrap align-items-start gap-3">
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h2 class="h6 fw-semibold mb-0">{{ $site->name }}</h2>
                            <span class="ag-badge ag-badge--{{ $site->statusVariant() }}" data-status-badge>
                                {{ $site->statusLabel() }}
                            </span>
                            @unless($site->canEditContent())
                                <span class="ag-badge ag-badge--muted"
                                      title="{{ $site->isReadOnlyAccount()
                                            ? 'Le compte WordPress « '.$site->wp_username.' » n’a pas le droit de modifier les articles'.($site->wp_role ? ' (rôle « '.$site->wp_role.' »)' : '').'.'
                                            : 'Sans Application Password, les articles sont consultables mais non modifiables.' }}">
                                    Lecture seule
                                </span>
                            @endunless
                        </div>

                        <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer"
                           class="ag-mono ag-muted text-decoration-none d-inline-block mt-1">
                            {{ $site->url }} <i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i>
                        </a>

                        @if($site->connection_message)
                            <p class="ag-hint mt-2 mb-0">{{ $site->connection_message }}</p>
                        @endif

                        <div class="d-flex flex-wrap gap-3 mt-3 small">
                            <span class="ag-muted">
                                <i class="bi bi-file-text me-1" aria-hidden="true"></i>
                                <strong data-articles-count>{{ $site->articles_count }}</strong> articles
                            </span>
                            <span class="ag-muted">
                                <i class="bi bi-tags me-1" aria-hidden="true"></i>
                                <strong data-categories-count>{{ $site->categories_count }}</strong> catégories
                            </span>
                            <span class="ag-muted">
                                <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
                                Synchro : <span data-last-sync>{{ $site->last_sync_at?->diffForHumans() ?? 'jamais' }}</span>
                            </span>
                            <span class="ag-muted">
                                <i class="bi bi-plug me-1" aria-hidden="true"></i>
                                Testé : <span data-checked-at>{{ $site->last_checked_at?->diffForHumans() ?? 'jamais' }}</span>
                            </span>
                        </div>

                        @php
                            $pendingSync = in_array($site->sync_status, ['queued', 'running'], true);
                        @endphp

                        {{-- Travail en attente qu'aucun worker ne prendra : le dire
                             plutôt que d'afficher un « en cours » qui n'avancera pas. --}}
                        <div class="d-flex align-items-center gap-2 mt-3" data-sync-progress
                             @unless($pendingSync && ! $queueStalled) hidden @endunless>
                            <span class="spinner-border spinner-border-sm text-secondary" aria-hidden="true"></span>
                            <span class="ag-hint">Synchronisation en cours… vous pouvez continuer à naviguer.</span>
                        </div>

                        <div class="d-flex align-items-start gap-2 mt-3" data-sync-stalled
                             @unless($pendingSync && $queueStalled) hidden @endunless>
                            <i class="bi bi-pause-circle text-warning" aria-hidden="true"></i>
                            <span class="ag-hint">
                                Synchronisation en attente : aucun worker ne traite la file.
                                Lancez <code>php artisan queue:work</code>, ou directement
                                <code>php artisan wp:sync {{ $site->id }}</code>.
                            </span>
                        </div>

                        @if($site->sync_status === 'failed' && $site->sync_message)
                            <div class="d-flex align-items-start gap-2 mt-3" data-sync-failed>
                                <i class="bi bi-exclamation-triangle text-danger" aria-hidden="true"></i>
                                <span class="ag-hint">{{ $site->sync_message }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-test-url="{{ route('sites.test', $site) }}">
                            <i class="bi bi-plug me-1" aria-hidden="true"></i> Tester
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-sync-url="{{ route('sites.sync', $site) }}">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Synchroniser
                        </button>
                        <a href="{{ route('sites.edit', $site) }}" class="btn btn-sm btn-outline-secondary">
                            Modifier
                        </a>
                        <form method="POST" action="{{ route('sites.destroy', $site) }}"
                              data-confirm="Supprimer « {{ $site->name }} » et tous ses articles synchronisés ? Le site WordPress lui-même n'est pas modifié.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    aria-label="Supprimer {{ $site->name }}">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="ag-card">
            <div class="ag-empty">
                <div class="ag-empty__icon"><i class="bi bi-globe2" aria-hidden="true"></i></div>
                <p class="ag-empty__title">Aucun site connecté</p>
                <p class="ag-empty__text">
                    Indiquez l’adresse de votre site WordPress ainsi qu’un identifiant et une
                    Application Password pour pouvoir auditer et corriger vos articles.
                </p>
                <a href="{{ route('sites.create') }}" class="btn btn-primary btn-sm mt-3">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site
                </a>
            </div>
        </div>
    @endforelse
</div>
@endsection
