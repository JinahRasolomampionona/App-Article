@php
    /** @var \App\Models\WordpressArticle $article */
    $issues = $article->relationLoaded('openIssues') ? $article->openIssues : $article->openIssues()->get();
    $visible = $issues->take(2);
@endphp

<tr data-article-id="{{ $article->id }}">
    <td>
        <input type="checkbox" class="form-check-input ag-row-check" value="{{ $article->id }}"
               aria-label="Sélectionner l’article {{ $article->title }}">
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
        @if($issues->isEmpty())
            <span class="ag-hint">—</span>
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

    {{-- Statut : sélecteur dès qu'un problème a été détecté, badge sinon.
         « OK » et l'absence d'audit ne se décrètent pas à la main. --}}
    <td>
        @if($article->statusIsEditable())
            <select class="form-select form-select-sm ag-status-select ag-status-select--{{ $article->statusVariant() }}"
                    data-status-url="{{ route('articles.status', $article) }}"
                    aria-label="Statut de l’article {{ $article->title }}">
                @foreach(\App\Models\WordpressArticle::manualStatuses() as $value => $label)
                    <option value="{{ $value }}" @selected($article->audit_status === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if($article->status_set_manually_at)
                <span class="ag-hint">Défini manuellement</span>
            @endif
        @elseif($article->statusLabel())
            <span class="ag-badge ag-badge--{{ $article->statusVariant() }}">
                {{ $article->statusLabel() }}
            </span>
        @else
            <span class="ag-hint">—</span>
        @endif
    </td>

    <td class="text-end">
        <div class="d-inline-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-audit-url="{{ route('articles.audit', $article) }}"
                    data-busy-label=""
                    data-bs-toggle="tooltip" title="Relancer l’audit"
                    aria-label="Relancer l’audit de {{ $article->title }}">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            </button>
            <a href="{{ route('articles.edit', $article) }}" class="btn btn-sm btn-outline-primary">Éditer</a>
        </div>
    </td>
</tr>
