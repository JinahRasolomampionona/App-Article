{{-- Articles non corrigés : le reste à faire, la contrepartie de l'historique. --}}

<div class="ag-card">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Articles non corrigés</h2>
        <span class="ag-hint ms-auto">{{ $pending->total() }} article(s) à corriger</span>
    </div>

    <div class="table-responsive">
        <table class="table ag-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Article</th>
                    <th scope="col">Site</th>
                    <th scope="col" class="text-end">Problèmes</th>
                    <th scope="col">Dernière analyse</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($pending as $article)
                <tr>
                    <td>
                        <a href="{{ route('articles.edit', $article) }}"
                           class="ag-table__title text-truncate">{{ $article->title }}</a>
                        <span class="ag-table__url">{{ $article->relativePath() }}</span>
                    </td>
                    <td><span class="ag-hint">{{ $article->site?->name }}</span></td>
                    <td class="text-end">
                        <span class="ag-badge ag-badge--danger">{{ $article->issues_count }}</span>
                    </td>
                    <td><span class="ag-hint">{{ $article->last_audited_at?->diffForHumans() ?? '—' }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('articles.edit', $article) }}"
                           class="btn btn-sm btn-outline-primary">Corriger</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <div class="ag-empty py-5">
                            <div class="ag-empty__icon"><i class="bi bi-check-circle" aria-hidden="true"></i></div>
                            <p class="ag-empty__title">Aucun article à corriger</p>
                            <p class="ag-empty__text">
                                Tous les articles audités sont conformes aux règles activées.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($pending->hasPages())
        <div class="ag-card__body border-top">
            {{ $pending->links() }}
        </div>
    @endif
</div>
