{{-- Prise en charge de l'article dans l'éditeur.

     Le détenteur prolonge son verrou par un heartbeat tant que la page est
     ouverte ; les autres voient qui travaille et consultent sans modifier. --}}
@php
    /** @var \App\Models\WordpressArticle $article */
    $me = auth()->user();
@endphp

<div class="ag-card mb-3 ag-lock-panel ag-lock-panel--{{ $lockState }}" id="ag-lock" data-keep-enabled
     data-state="{{ $lockState }}"
     data-heartbeat-url="{{ route('articles.heartbeat', $article) }}"
     data-heartbeat-seconds="{{ $heartbeatSeconds }}"
     data-take-url="{{ route('articles.take', $article) }}"
     data-release-url="{{ route('articles.release', $article) }}"
     data-finish-url="{{ route('articles.finish', $article) }}"
     data-index-url="{{ route('articles.index', ['site' => $article->wordpress_site_id]) }}">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Prise en charge</h2>
        <span class="ms-auto" data-lock-badge>
            @switch($lockState)
                @case('mine')
                    <span class="ag-lock ag-lock--mine"><span class="ag-lock__dot" aria-hidden="true"></span> En cours par vous</span>
                    @break
                @case('other')
                    <span class="ag-lock ag-lock--other"><span class="ag-lock__dot" aria-hidden="true"></span> En cours par {{ $article->activeAgentName() }}</span>
                    @break
                @default
                    <span class="ag-lock ag-lock--available"><span class="ag-lock__dot" aria-hidden="true"></span> Disponible</span>
            @endswitch
        </span>
    </div>

    <div class="ag-card__body">
        {{-- Message affiché si le verrou est perdu pendant l'édition. --}}
        <div class="alert alert-warning py-2 px-3 small mb-2" role="alert" data-lock-lost hidden></div>

        @if($lockState === 'mine')
            <p class="ag-hint mb-3" data-lock-info>
                Vous seul pouvez modifier cet article. Il reste réservé tant que cette page est ouverte
                (libéré automatiquement après {{ config('articleguard.locks.ttl_minutes') }} min d’inactivité).
            </p>
            <div class="d-grid gap-2" data-lock-actions>
                <button type="button" class="btn btn-success" data-finish
                        @disabled(! $article->site->hasCredentials())>
                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Terminer la correction
                </button>
                <button type="button" class="btn btn-outline-secondary" data-release>
                    <i class="bi bi-unlock me-1" aria-hidden="true"></i> Libérer l’article
                </button>
            </div>
            <p class="ag-hint mt-2 mb-0">
                « Terminer » enregistre vos modifications, relance l’audit puis libère l’article.
            </p>
        @elseif($lockState === 'other')
            <p class="small mb-2">
                <strong>{{ $article->activeAgentName() }}</strong> travaille sur cet article
                depuis {{ $article->locked_at?->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}.
                Vous pouvez le consulter, mais pas le modifier.
            </p>
            @if($me->isAdmin())
                <button type="button" class="btn btn-sm btn-outline-secondary w-100" data-release
                        data-confirm-message="Libérer cet article ? {{ $article->activeAgentName() }} ne pourra plus enregistrer ses modifications.">
                    <i class="bi bi-unlock me-1" aria-hidden="true"></i> Libérer l’article (Admin)
                </button>
            @endif
        @else
            <p class="small mb-2">Cet article est disponible. Prenez-le pour le corriger : les autres agents le verront « En cours ».</p>
            <button type="button" class="btn btn-primary w-100" data-take>
                <i class="bi bi-person-check me-1" aria-hidden="true"></i> Prendre l’article
            </button>
        @endif
    </div>
</div>
