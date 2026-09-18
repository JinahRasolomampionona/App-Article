@if($articles->hasPages())
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="ag-hint">
            Page {{ $articles->currentPage() }} sur {{ $articles->lastPage() }}
            · {{ $articles->total() }} article(s)
        </span>
        <nav class="ms-auto" aria-label="Pagination des articles">
            {{ $articles->onEachSide(1)->links() }}
        </nav>
    </div>
@else
    <span class="ag-hint">{{ $articles->total() }} article(s)</span>
@endif
