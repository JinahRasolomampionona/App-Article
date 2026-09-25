@php
    $sites = $navSites ?? collect();

    // Les écrans liés à un article donné n'ont plus de sens une fois le site
    // changé : on repart de la liste des articles du nouveau site.
    $switchRedirect = request()->routeIs('articles.show', 'articles.edit', 'sites.edit')
        ? route('articles.index')
        : null;
@endphp

@if($sites->isNotEmpty())
    <div class="dropdown" @if($switchRedirect) data-select-redirect="{{ $switchRedirect }}" @endif>
        <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-2"
                data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-globe2" aria-hidden="true"></i>
            <span class="text-truncate" style="max-width: 11rem;">
                {{ $navCurrentSite?->name ?? 'Choisir un site' }}
            </span>
            <i class="bi bi-chevron-down small" aria-hidden="true"></i>
        </button>

        <ul class="dropdown-menu dropdown-menu-end" style="min-width: 15rem;">
            <li><h6 class="dropdown-header">Site WordPress</h6></li>
            @foreach($sites as $site)
                <li>
                    <button type="button"
                            class="dropdown-item d-flex align-items-center gap-2 @if($navCurrentSite?->id === $site->id) active @endif"
                            data-select-site="{{ route('sites.select', $site) }}">
                        <span class="flex-grow-1 text-truncate">
                            {{ $site->name }}
                            <span class="d-block small opacity-75">{{ parse_url($site->url, PHP_URL_HOST) }}</span>
                        </span>
                        @if($navCurrentSite?->id === $site->id)
                            <i class="bi bi-check2" aria-hidden="true"></i>
                        @endif
                    </button>
                </li>
            @endforeach
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="{{ route('sites.create') }}">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site
            </a></li>

        </ul>
    </div>
@else
    <a href="{{ route('sites.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site
    </a>

@endif
