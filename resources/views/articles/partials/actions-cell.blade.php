@php
    /** @var \App\Models\WordpressArticle $article */
    $me = auth()->user();
    $state = $article->lockStateFor($me);
@endphp

<div class="d-inline-flex gap-1" data-lock-key="{{ $state }}:{{ $article->activeAgentId() ?? 0 }}">
    <button type="button" class="btn btn-sm btn-outline-secondary"
            data-audit-url="{{ route('articles.audit', $article) }}"
            data-busy-label=""
            data-bs-toggle="tooltip" title="Relancer l’audit"
            aria-label="Relancer l’audit de {{ $article->title }}">
        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
    </button>

    @if($state === 'mine')
        <button type="button" class="btn btn-sm btn-outline-secondary"
                data-release-url="{{ route('articles.release', $article) }}"
                data-bs-toggle="tooltip" title="Libérer l’article"
                aria-label="Libérer l’article {{ $article->title }}">
            <i class="bi bi-unlock" aria-hidden="true"></i>
        </button>
        <a href="{{ route('articles.edit', $article) }}" class="btn btn-sm btn-primary">Éditer</a>
    @elseif($state === 'other')
        @if($me->isAdmin())
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-release-url="{{ route('articles.release', $article) }}"
                    data-release-confirm="Libérer cet article ? {{ $article->activeAgentName() }} ne pourra plus enregistrer ses modifications."
                    data-bs-toggle="tooltip" title="Libérer l’article (Admin)"
                    aria-label="Libérer l’article {{ $article->title }}">
                <i class="bi bi-unlock" aria-hidden="true"></i>
            </button>
        @endif
        <a href="{{ route('articles.edit', $article) }}" class="btn btn-sm btn-outline-secondary"
           data-bs-toggle="tooltip" title="Consultation seule : en cours par {{ $article->activeAgentName() }}">
            <i class="bi bi-eye me-1" aria-hidden="true"></i>Voir
        </a>
    @else
        <button type="button" class="btn btn-sm btn-outline-primary"
                data-take-url="{{ route('articles.take', $article) }}"
                data-edit-url="{{ route('articles.edit', $article) }}"
                aria-label="Prendre et éditer l’article {{ $article->title }}">
            Prendre
        </button>
    @endif
</div>
