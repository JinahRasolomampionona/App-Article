@extends('layouts.app')

@section('title', 'Sites WordPress')
@section('heading', 'Sites WordPress')
@section('subheading', 'Vos sites connectés avec vos propres identifiants WordPress.')

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
    @if($otherSites->isNotEmpty())
        <h2 class="h6 fw-semibold mb-2">Mes sites</h2>
    @endif

    {{-- Sites connectés par l'utilisateur : il gère sa propre connexion
         (tester, synchroniser, modifier, supprimer). --}}
    @forelse($sites as $site)
        @php
            $others = $site->connections->where('user_id', '!=', auth()->id())->pluck('user.name')->filter();
        @endphp

        <div class="ag-card mb-3" data-site-row
             data-status-url="{{ route('sites.sync-status', $site) }}">
            <div class="ag-card__body">
                <div class="d-flex flex-column flex-md-row align-items-start gap-3">
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

                        <p class="ag-hint mt-1 mb-0">
                            <i class="bi bi-person me-1" aria-hidden="true"></i>
                            Connecté avec votre compte WordPress
                            @if($site->wp_username)
                                « {{ $site->wp_username }} »
                            @endif
                            @if($others->isNotEmpty())
                                · aussi connecté par {{ $others->join(', ', ' et ') }} (articles partagés)
                            @endif
                        </p>

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
                            // « running » : un worker ou `wp:sync` traite déjà le site.
                            // Seul un « queued » peut attendre un worker absent.
                            $syncRunning = $site->isSyncRunning();
                            $syncStalled = $site->sync_status === 'queued' && $queueStalled;
                            $pendingSync = $syncRunning || ($site->sync_status === 'queued' && ! $syncStalled);
                        @endphp

                        <div class="d-flex align-items-center gap-2 mt-3" data-sync-progress
                             data-pending="{{ $pendingSync ? '1' : '0' }}"
                             @unless($pendingSync) hidden @endunless>
                            <span class="spinner-border spinner-border-sm text-secondary" aria-hidden="true"></span>
                            <span class="ag-hint" role="status">
                                La synchronisation est déjà lancée. Patientez quelques minutes :
                                les articles apparaissent au fur et à mesure, vous pouvez continuer à naviguer.
                            </span>
                        </div>

                        {{-- Travail en attente qu'aucun worker ne prendra : le dire
                             plutôt que d'afficher un « en cours » qui n'avancera pas. --}}
                        <div class="d-flex align-items-start gap-2 mt-3" data-sync-stalled
                             @unless($syncStalled) hidden @endunless>
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

                    <div class="d-flex flex-wrap flex-md-nowrap gap-2 flex-shrink-0">
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
                              data-confirm="{{ $others->isNotEmpty()
                                  ? 'Retirer « '.$site->name.' » de vos sites ? Il reste disponible pour '.$others->join(', ', ' et ').', avec ses articles.'
                                  : 'Supprimer « '.$site->name.' » et tous ses articles synchronisés ? Le site WordPress lui-même n\'est pas modifié.' }}">
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
        <div class="ag-card mb-3">
            <div class="ag-empty">
                <div class="ag-empty__icon"><i class="bi bi-globe2" aria-hidden="true"></i></div>
                <p class="ag-empty__title">Aucun site connecté</p>
                <p class="ag-empty__text">
                    Indiquez l’adresse de votre site WordPress ainsi que votre identifiant et votre
                    Application Password pour pouvoir auditer et corriger ses articles.
                </p>
                <a href="{{ route('sites.create') }}" class="btn btn-primary btn-sm mt-3">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site
                </a>
            </div>
        </div>
    @endforelse

    {{-- Admin : sites connectés uniquement par les agents, pour suivre leur
         travail. Leurs identifiants restent la propriété des agents. --}}
    @if($otherSites->isNotEmpty())
        <h2 class="h6 fw-semibold mt-4 mb-2">Sites connectés par les agents</h2>

        <div class="ag-card">
            <div class="table-responsive">
                <table class="table ag-table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Site</th>
                            <th scope="col">Connecté par</th>
                            <th scope="col" class="text-end">Articles</th>
                            <th scope="col">Synchro</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($otherSites as $site)
                        <tr>
                            <td>
                                <span class="ag-table__title">{{ $site->name }}</span>
                                <span class="ag-table__url">{{ $site->url }}</span>
                            </td>
                            <td>
                                @forelse($site->connections as $connection)
                                    <span class="ag-chip">{{ $connection->user?->name }}</span>
                                @empty
                                    <span class="ag-hint">—</span>
                                @endforelse
                            </td>
                            <td class="text-end">{{ $site->articles_count }}</td>
                            <td><span class="ag-hint">{{ $site->last_sync_at?->diffForHumans() ?? 'jamais' }}</span></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-sync-url="{{ route('sites.sync', $site) }}">
                                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Synchroniser
                                    </button>
                                    <a href="{{ route('articles.index', ['site' => $site->id]) }}"
                                       class="btn btn-sm btn-outline-secondary">Voir les articles</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
