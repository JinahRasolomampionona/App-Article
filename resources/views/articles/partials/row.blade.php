@php
    /** @var \App\Models\WordpressArticle $article */
    $issues = $article->relationLoaded('openIssues') ? $article->openIssues : $article->openIssues()->get();
    $visible = $issues->take(2);
    $preview = \App\Services\Audit\ArticleImageReport::preview(
        $article,
        isset($site) ? $site->url : ($article->relationLoaded('site') ? $article->site?->url : null)
    );
    $imageIssues = $issues->whereIn('rule_type', ['featured_image_unreachable', 'body_image_broken', 'image_blurry', 'image_low_resolution', 'image_possibly_incoherent'])->count();
@endphp

<tr data-article-id="{{ $article->id }}">
    <td>
        <input type="checkbox" class="form-check-input ag-row-check" value="{{ $article->id }}"
               aria-label="Sélectionner l’article {{ $article->title }}">
    </td>

    {{-- Images : miniature de la première, ouvre la liste complète avec leur qualité. --}}
    <td>
        @if($preview['count'] > 0)
            <button type="button" class="ag-row-thumb {{ $imageIssues > 0 ? 'ag-row-thumb--alert' : '' }}"
                    data-images-url="{{ route('articles.images', $article) }}"
                    aria-label="Voir les {{ $preview['count'] }} image(s) de {{ $article->title }}"
                    data-bs-toggle="tooltip"
                    title="{{ $preview['count'] }} image(s){{ $imageIssues > 0 ? ' · '.$imageIssues.' à vérifier' : '' }}">
                @if($preview['thumb'])
                    <img src="{{ $preview['thumb'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" data-thumb-img>
                @endif
                <i class="bi bi-image" aria-hidden="true"></i>
                <span class="ag-row-thumb__count">{{ $preview['count'] }}</span>
            </button>
        @else
            <span class="ag-row-thumb ag-row-thumb--empty" data-bs-toggle="tooltip" title="Aucune image">
                <i class="bi bi-image" aria-hidden="true"></i>
                <span class="visually-hidden">Aucune image</span>
            </span>
        @endif
    </td>

    <td>
        <a href="{{ route('articles.edit', $article) }}" class="ag-table__title text-truncate"
           title="{{ $article->title }}">{{ $article->title }}</a>
        <span class="ag-hint">#{{ $article->wp_id }}
            @if($article->status !== 'publish')
                · <span class="ag-chip">{{ $article->status }}</span>
            @endif
        </span>
    </td>

    <td>
        <div class="d-flex flex-wrap gap-1">
            @forelse($article->categories->take(3) as $category)
                <span class="ag-chip">{{ $category->name }}</span>
            @empty
                <span class="ag-hint">—</span>
            @endforelse
            @if($article->categories->count() > 3)
                <span class="ag-chip">+{{ $article->categories->count() - 3 }}</span>
            @endif
        </div>
    </td>

    <td>
        @if($article->link)
            <a href="{{ $article->link }}" target="_blank" rel="noopener noreferrer"
               class="ag-table__url text-decoration-none" title="{{ $article->link }}">
                {{ $article->relativePath() }}
            </a>
        @else
            <span class="ag-table__url">{{ $article->relativePath() }}</span>
        @endif
    </td>

    {{-- Remarques : vides tant qu'aucun problème n'est ouvert --}}
    <td>
        @php $notes = $article->currentNotes(); @endphp
        @if($notes->isNotEmpty())
            {{-- Commentaire de l'Admin : ce qu'il reste à modifier. --}}
            <span class="ag-badge ag-badge--primary mb-1" data-bs-toggle="tooltip"
                  title="{{ $notes->first()->author?->name ?? 'Admin' }} : {{ \Illuminate\Support\Str::limit($notes->first()->body, 200) }}">
                <i class="bi bi-chat-left-text" aria-hidden="true"></i> Commentaire admin
            </span>
        @endif
        @if($issues->isEmpty())
            @if($notes->isEmpty())<span class="ag-hint">—</span>@endif
        @else
            <div class="d-flex flex-wrap align-items-center gap-1">
                @foreach($visible as $issue)
                    <span class="ag-badge ag-badge--{{ $issue->severityVariant() }}"
                          data-bs-toggle="tooltip" title="{{ $issue->message }}">
                        {{ \App\Support\IssueCatalog::short($issue->rule_type) }}
                    </span>
                @endforeach

                @if($issues->count() > $visible->count())
                    <button type="button" class="btn btn-link btn-sm p-0 small text-decoration-none"
                            data-issues-url="{{ route('articles.issues', $article) }}">
                        +{{ $issues->count() - $visible->count() }} autre(s)
                    </button>
                @else
                    <button type="button" class="btn btn-link btn-sm p-0 small text-decoration-none"
                            data-issues-url="{{ route('articles.issues', $article) }}"
                            aria-label="Voir les {{ $issues->count() }} problème(s) de {{ $article->title }}">
                        Détails
                    </button>
                @endif
            </div>
        @endif
    </td>

    <td data-status-cell>
        @include('articles.partials.status-cell', ['article' => $article])
    </td>

    {{-- Statut de traitement (qui travaille sur l'article), rafraîchi par
         sondage sans recharger la ligne. --}}
    <td data-agent-cell>
        @include('articles.partials.agent-cell', ['article' => $article])
    </td>

    <td class="text-end" data-actions-cell>
        @include('articles.partials.actions-cell', ['article' => $article])
    </td>

    {{-- Admin : rendre l'article à un agent (après « Corrigé », s'il reste
         une modification à faire), avec un commentaire facultatif. --}}
    @if(auth()->user()->isAdmin())
        <td class="text-end">
            <button type="button" class="btn btn-sm {{ $article->isCompleted() ? 'btn-outline-primary' : 'btn-outline-secondary' }}"
                    data-reassign-url="{{ route('articles.reassign', $article) }}"
                    data-reassign-agent="{{ $article->completed_by ?? $article->activeAgentId() ?? '' }}"
                    data-reassign-site="{{ $article->wordpress_site_id }}"
                    data-reassign-title="{{ $article->title }}"
                    data-bs-toggle="tooltip" title="Réassigner à un agent"
                    aria-label="Réassigner l’article {{ $article->title }}">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                <span class="d-none d-xl-inline ms-1">Réassigner</span>
            </button>
        </td>
    @endif
</tr>
