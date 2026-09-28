@extends('layouts.app')

@php $isAdmin = auth()->user()->isAdmin(); @endphp

@section('title', 'Sites WordPress')
@section('heading', 'Sites WordPress')
@section('subheading', $isAdmin
    ? 'Connectez vos sites, testez-les et assignez-les à vos agents.'
    : 'Sites qui vous sont assignés par l’administrateur.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <span class="active">Sites WordPress</span>
@endsection

@section('actions')
    @can('create', \App\Models\WordpressSite::class)
        <a href="{{ route('sites.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site
        </a>
    @endcan
@endsection

@section('content')
<div id="ag-sites">
    @forelse($sites as $site)
        @php
            $myAssignment = $isAdmin ? null : $site->assignmentOf(auth()->user());
        @endphp

        <div class="ag-card mb-3" data-site-row
             data-status-url="{{ route('sites.sync-status', $site) }}">
            <div class="ag-card__body">
                <div class="d-flex flex-column flex-md-row align-items-start gap-3">
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h2 class="h6 fw-semibold mb-0">{{ $site->name }}</h2>

                            @if($myAssignment)
                                {{-- Espace agent : état fixé par l'Admin. --}}
                                <span class="ag-badge ag-badge--{{ $myAssignment->statusVariant() }}">
                                    <i class="bi {{ $myAssignment->isDone() ? 'bi-check2-circle' : 'bi-hourglass-split' }}" aria-hidden="true"></i>
                                    {{ $myAssignment->statusLabel() }}
                                </span>
                            @else
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
                            @endif
                        </div>

                        <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer"
                           class="ag-mono ag-muted text-decoration-none d-inline-block mt-1">
                            {{ $site->url }} <i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i>
                        </a>

                        @if($myAssignment)
                            <p class="ag-hint mt-1 mb-0">
                                Assigné {{ $myAssignment->assigned_at?->diffForHumans() }}
                                @if($myAssignment->isDone() && $myAssignment->completed_at)
                                    · terminé {{ $myAssignment->completed_at->diffForHumans() }}
                                @endif
                            </p>
                        @elseif($site->connection_message)
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
                            @if($isAdmin)
                                <span class="ag-muted">
                                    <i class="bi bi-plug me-1" aria-hidden="true"></i>
                                    Testé : <span data-checked-at>{{ $site->last_checked_at?->diffForHumans() ?? 'jamais' }}</span>
                                </span>
                            @endif
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
                        @can('test', $site)
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-test-url="{{ route('sites.test', $site) }}">
                                <i class="bi bi-plug me-1" aria-hidden="true"></i> Tester
                            </button>
                        @endcan
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-sync-url="{{ route('sites.sync', $site) }}">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Synchroniser
                        </button>
                        @can('update', $site)
                            <a href="{{ route('sites.edit', $site) }}" class="btn btn-sm btn-outline-secondary">
                                Modifier
                            </a>
                        @endcan
                        @can('delete', $site)
                            <form method="POST" action="{{ route('sites.destroy', $site) }}"
                                  data-confirm="Supprimer « {{ $site->name }} », ses articles synchronisés et ses assignations ? Le site WordPress lui-même n'est pas modifié.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        aria-label="Supprimer {{ $site->name }}">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        @endcan
                    </div>
                </div>

                {{-- Admin : agents assignés, leur état et leur travail sur ce site. --}}
                @can('assign', $site)
                    @php $activity = $agentActivity[$site->id] ?? []; @endphp

                    <div class="border-top mt-3 pt-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="small fw-semibold">Agents assignés</span>
                            <button type="button" class="btn btn-sm btn-outline-primary ms-auto"
                                    data-bs-toggle="modal" data-bs-target="#ag-assign-{{ $site->id }}">
                                <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Assigner
                            </button>
                        </div>

                        @forelse($site->agentAssignments->sortBy(fn ($a) => $a->user?->name) as $assignment)
                            @php $stats = $activity[$assignment->user_id] ?? []; @endphp

                            <div class="d-flex flex-wrap align-items-center gap-2 py-1">
                                <span class="fw-semibold small">{{ $assignment->user?->name }}</span>
                                <span class="ag-badge ag-badge--{{ $assignment->statusVariant() }}"
                                      @if($assignment->isDone() && $assignment->completed_at)
                                          title="Terminé le {{ $assignment->completed_at->format('d/m/Y à H:i') }} — le site n’apparaît plus dans son espace."
                                      @endif>
                                    {{ $assignment->statusLabel() }}
                                </span>
                                <span class="ag-hint">
                                    {{ $stats['fixed'] ?? 0 }} corrigé(s) · {{ $stats['in_progress'] ?? 0 }} en cours
                                </span>
                                <div class="ms-auto d-flex gap-1">
                                    <a href="{{ route('articles.index', ['site' => $site->id, 'agent' => $assignment->user_id]) }}"
                                       class="btn btn-sm btn-link text-decoration-none">Voir ses articles</a>
                                    <form method="POST"
                                          action="{{ route('sites.assignment-status', [$site, $assignment->user_id]) }}">
                                        @csrf
                                        @if($assignment->isDone())
                                            <input type="hidden" name="status" value="in_progress">
                                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                                    title="Le site réapparaît « En cours » dans l’espace de {{ $assignment->user?->name }}.">
                                                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Réassigner
                                            </button>
                                        @else
                                            <input type="hidden" name="status" value="done">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2 me-1" aria-hidden="true"></i> Terminer
                                            </button>
                                        @endif
                                    </form>
                                </div>
                            </div>
                        @empty
                            <p class="ag-hint mb-0">Aucun agent assigné à ce site.</p>
                        @endforelse
                    </div>

                    {{-- Choix des agents assignés au site. --}}
                    <div class="modal fade" id="ag-assign-{{ $site->id }}" tabindex="-1"
                         aria-labelledby="ag-assign-title-{{ $site->id }}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <form method="POST" action="{{ route('sites.assign', $site) }}" class="modal-content">
                                @csrf
                                <div class="modal-header">
                                    <h2 class="modal-title h6 mb-0" id="ag-assign-title-{{ $site->id }}">
                                        Assigner « {{ $site->name }} »
                                    </h2>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                                </div>
                                <div class="modal-body">
                                    @php
                                        $assigned = $site->agentAssignments->reject->isDone()->pluck('user_id')->all();
                                        $finished = $site->agentAssignments->filter->isDone()->pluck('user_id')->all();
                                    @endphp

                                    @forelse($agents as $agent)
                                        <label class="form-check">
                                            <input class="form-check-input" type="checkbox" name="agents[]"
                                                   value="{{ $agent->id }}" @checked(in_array($agent->id, $assigned, true))>
                                            <span class="form-check-label">
                                                {{ $agent->name }}
                                                @unless($agent->is_active)
                                                    <span class="ag-hint">(désactivé)</span>
                                                @endunless
                                                @if(in_array($agent->id, $finished, true))
                                                    <span class="ag-hint">(terminé)</span>
                                                @endif
                                            </span>
                                        </label>
                                    @empty
                                        <p class="ag-hint mb-0">
                                            Aucun compte agent. <a href="{{ route('agents.create') }}">Créer un agent</a>.
                                        </p>
                                    @endforelse

                                    <p class="ag-hint mt-3 mb-0">
                                        Le site apparaît aussitôt, « En cours », dans l’espace de chaque agent coché.
                                        Décocher un agent lui retire l’accès et libère ses articles en cours.
                                        Cocher un agent « Terminé » lui réassigne le site.
                                    </p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                                    <button type="submit" class="btn btn-sm btn-primary">Enregistrer</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endcan
            </div>
        </div>
    @empty
        <div class="ag-card">
            <div class="ag-empty">
                <div class="ag-empty__icon"><i class="bi bi-globe2" aria-hidden="true"></i></div>
                @if($isAdmin)
                    <p class="ag-empty__title">Aucun site connecté</p>
                    <p class="ag-empty__text">
                        Indiquez l’adresse du site WordPress ainsi qu’un identifiant et une
                        Application Password, puis assignez-le à vos agents.
                    </p>
                    <a href="{{ route('sites.create') }}" class="btn btn-primary btn-sm mt-3">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site
                    </a>
                @else
                    <p class="ag-empty__title">Aucun site assigné</p>
                    <p class="ag-empty__text">
                        L’administrateur ne vous a pas encore assigné de site. Il apparaîtra ici
                        dès l’assignation, sans connexion à faire de votre côté.
                    </p>
                @endif
            </div>
        </div>
    @endforelse
</div>
@endsection
