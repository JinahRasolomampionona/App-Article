@forelse($articles as $article)
    @include('articles.partials.row', ['article' => $article])
@empty
    <tr>
        <td colspan="7">
            @if(($siteHasArticles ?? true) === false)
                {{-- Site connecté mais jamais synchronisé : ajuster les filtres n'y changerait rien. --}}
                <div class="ag-empty py-5">
                    <div class="ag-empty__icon"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></div>
                    <p class="ag-empty__title">Aucun article synchronisé pour ce site</p>
                    <p class="ag-empty__text">
                        Lancez une synchronisation pour récupérer les articles
                        @isset($site) de {{ $site->name }} @endisset depuis WordPress.
                    </p>
                    @isset($site)
                        <button type="button" class="btn btn-primary btn-sm mt-3"
                                data-sync-url="{{ route('sites.sync', $site) }}" id="ag-sync-empty">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Synchroniser maintenant
                        </button>
                    @endisset
                </div>
            @else
                <div class="ag-empty py-5">
                    <div class="ag-empty__icon"><i class="bi bi-search" aria-hidden="true"></i></div>
                    <p class="ag-empty__title">Aucun article ne correspond</p>
                    <p class="ag-empty__text">
                        Ajustez la recherche ou les catégories sélectionnées pour élargir les résultats.
                    </p>
                </div>
            @endif
        </td>
    </tr>
@endforelse
