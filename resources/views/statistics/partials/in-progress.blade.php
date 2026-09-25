{{-- Articles en cours de traitement : qui travaille sur quoi, depuis quand. --}}

<div class="ag-card mb-3">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Articles en cours</h2>
        <span class="ag-hint ms-auto">{{ $inProgress->total() }} article(s) pris en charge</span>
    </div>

    <div class="table-responsive">
        <table class="table ag-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Article</th>
                    <th scope="col">Site</th>
                    <th scope="col">Agent</th>
                    <th scope="col">Statut d’audit</th>
                    <th scope="col">Pris</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($inProgress as $article)
                <tr>
                    <td>
                        <a href="{{ route('articles.edit', $article) }}"
                           class="ag-table__title text-truncate">{{ $article->title }}</a>
                        <span class="ag-table__url">{{ $article->relativePath() }}</span>
                    </td>
                    <td><span class="ag-hint">{{ $article->site?->name }}</span></td>
                    <td>
                        <span class="ag-lock ag-lock--{{ $article->lockStateFor(auth()->user()) }}">
                            <span class="ag-lock__dot" aria-hidden="true"></span>
                            {{ $article->isLockedBy(auth()->user()) ? 'Vous' : $article->activeAgentName() }}
                        </span>
                    </td>
                    <td>
                        @if($article->statusLabel())
                            <span class="ag-badge ag-badge--{{ $article->statusVariant() }}">{{ $article->statusLabel() }}</span>
                        @else
                            <span class="ag-hint">En attente d’analyse</span>
                        @endif
                    </td>
                    <td>
                        <span class="ag-hint">{{ $article->locked_at?->diffForHumans() ?? '—' }}</span>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('articles.edit', $article) }}" class="btn btn-sm btn-outline-secondary">
                            {{ $article->isLockedBy(auth()->user()) ? 'Continuer' : 'Voir' }}
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <p class="ag-hint mb-0 py-3 text-center">Aucun article n’est en cours de traitement.</p>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($inProgress->hasPages())
        <div class="ag-card__body border-top">
            {{ $inProgress->links() }}
        </div>
    @endif
</div>
