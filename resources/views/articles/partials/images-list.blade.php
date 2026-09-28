{{-- Liste des images d'un article (modal « Images de l'article »).
     Les URL viennent d'un site tiers : seules les URL http(s) sont affichées
     (voir ArticleImageReport::displayUrl), toujours échappées. --}}

@if(empty($images))
    <div class="ag-empty py-4">
        <div class="ag-empty__icon"><i class="bi bi-image" aria-hidden="true"></i></div>
        <p class="ag-empty__title">Aucune image</p>
        <p class="ag-empty__text">Cet article n’a ni image à la une ni image dans son contenu.</p>
    </div>
@else
    <div class="ag-image-report">
        @foreach($images as $index => $image)
            <figure class="ag-image-report__item">
                <div class="ag-image-report__preview">
                    @if($image['display_url'])
                        <a href="{{ $image['display_url'] }}" target="_blank" rel="noopener noreferrer"
                           title="Ouvrir l’image en taille réelle">
                            <img src="{{ $image['display_url'] }}" alt="{{ $image['alt'] }}"
                                 loading="lazy" referrerpolicy="no-referrer" data-report-img>
                        </a>
                    @endif
                    <div class="ag-image-report__fallback" @if($image['display_url']) hidden @endif data-report-fallback>
                        <i class="bi bi-image-alt" aria-hidden="true"></i>
                        <span>Image non affichable</span>
                    </div>
                    <span class="ag-image-report__index">{{ $index + 1 }}</span>
                </div>

                <figcaption class="ag-image-report__body">
                    <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
                        @if($image['scope'] === 'featured')
                            <span class="ag-chip"><i class="bi bi-star me-1" aria-hidden="true"></i>Image à la une</span>
                        @else
                            <span class="ag-chip">Contenu{{ $image['in_hero'] ? ' · bandeau' : '' }}</span>
                        @endif
                        @if($image['width'] && $image['height'])
                            <span class="ag-hint">{{ $image['width'] }}×{{ $image['height'] }} px</span>
                        @endif
                        @if($image['bytes'])
                            <span class="ag-hint">· {{ \Illuminate\Support\Number::fileSize($image['bytes'], 1) }}</span>
                        @endif
                    </div>

                    <div class="d-flex flex-wrap gap-1 mb-1">
                        @foreach($image['verdicts'] as $verdict)
                            <span class="ag-badge ag-badge--{{ $verdict['variant'] }}"
                                  @if($verdict['detail']) data-bs-toggle="tooltip" title="{{ $verdict['detail'] }}" @endif>
                                <i class="bi {{ $verdict['icon'] }}" aria-hidden="true"></i> {{ $verdict['label'] }}
                            </span>
                        @endforeach
                    </div>

                    <div class="ag-hint text-truncate" title="{{ $image['src'] }}">
                        {{ basename(parse_url($image['src'], PHP_URL_PATH) ?: $image['src']) }}
                    </div>
                    <div class="ag-hint text-truncate">
                        Alt : {{ $image['alt'] !== '' ? $image['alt'] : '— (vide)' }}
                    </div>
                </figcaption>
            </figure>
        @endforeach
    </div>

    <p class="ag-hint mt-3 mb-0">
        Flou, résolution et cohérence sont des détections heuristiques, issues du dernier audit{{ $article->last_audited_at ? " (".$article->last_audited_at->diffForHumans().")" : "" }}.
    </p>
@endif
