@php
    $needsFix = $navNeedsFix ?? 0;
    $isAdmin = auth()->user()->isAdmin();

    // Identifiants uniques : la sidebar est rendue deux fois (fixe et offcanvas),
    // et `aria-controls` doit désigner un seul élément.
    $scope = $navScope ?? 'main';

    // Les pages d'administration ne sont proposées qu'à l'Admin ; les routes
    // elles-mêmes sont protégées côté serveur (porte « admin »).
    $sections = [
        [
            'key' => 'articleguard',
            'label' => 'ArticleGuard',
            'badge' => $needsFix,
            'links' => array_values(array_filter([
                [
                    'href' => route('dashboard'),
                    'icon' => 'bi-grid-1x2',
                    'label' => 'Dashboard',
                    'active' => request()->routeIs('dashboard'),
                ],
                [
                    'href' => route('articles.index'),
                    'icon' => 'bi-file-text',
                    'label' => 'Articles',
                    'active' => request()->routeIs('articles.*'),
                    'badge' => $needsFix,
                    'badgeTitle' => $needsFix.' article(s) à corriger',
                ],
                [
                    'href' => route('audits.index'),
                    'icon' => 'bi-clipboard-check',
                    'label' => 'Audits',
                    'active' => request()->routeIs('audits.*'),
                ],
                [
                    'href' => route('sites.index'),
                    'icon' => 'bi-globe2',
                    'label' => 'Sites WordPress',
                    'active' => request()->routeIs('sites.*'),
                ],
                [
                    'href' => route('statistics.index'),
                    'icon' => 'bi-bar-chart-line',
                    'label' => $isAdmin ? 'Statistiques' : 'Mes statistiques',
                    'active' => request()->routeIs('statistics.*'),
                ],
            ])),
        ],
    ];

    if ($isAdmin) {
        $sections[] = [
            'key' => 'configuration',
            'label' => 'Configuration',
            'links' => [
                [
                    'href' => route('agents.index'),
                    'icon' => 'bi-people',
                    'label' => 'Agents',
                    'active' => request()->routeIs('agents.*'),
                ],
                [
                    'href' => route('settings.edit'),
                    'icon' => 'bi-sliders',
                    'label' => 'Paramètres',
                    'active' => request()->routeIs('settings.*'),
                ],
            ],
        ];
    }
@endphp

<a href="{{ route('dashboard') }}" class="ag-brand">
    <span class="ag-brand__mark" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
    <span>
        <span class="ag-brand__name d-block">{{ config('articleguard.name') }}</span>
        <span class="ag-brand__tagline">{{ config('articleguard.tagline') }}</span>
    </span>
</a>

<nav class="ag-nav" aria-label="Navigation principale">
    @foreach($sections as $section)
        @php $panelId = 'ag-nav-'.$section['key'].'-'.$scope; @endphp

        <div class="ag-nav__group" data-nav-group="{{ $section['key'] }}">
            <button type="button" class="ag-nav__section" data-nav-toggle
                    aria-expanded="true" aria-controls="{{ $panelId }}">
                <i class="bi bi-chevron-down ag-nav__chevron" aria-hidden="true"></i>
                <span>{{ $section['label'] }}</span>
                @if(($section['badge'] ?? 0) > 0)
                    <span class="ag-nav__badge ag-nav__badge--section"
                          title="{{ $section['badge'] }} article(s) à corriger">{{ $section['badge'] }}</span>
                @endif
            </button>

            <div class="ag-nav__items" id="{{ $panelId }}">
                <div class="ag-nav__links">
                @foreach($section['links'] as $link)
                    <a href="{{ $link['href'] }}"
                       class="ag-nav__link @if($link['active']) is-active @endif"
                       data-label="{{ $link['label'] }}"
                       @if($link['active']) aria-current="page" @endif>
                        <i class="bi {{ $link['icon'] }}" aria-hidden="true"></i>
                        <span class="ag-nav__label">{{ $link['label'] }}</span>
                        @if(($link['badge'] ?? 0) > 0)
                            <span class="ag-nav__badge" title="{{ $link['badgeTitle'] ?? '' }}">{{ $link['badge'] }}</span>
                        @endif
                    </a>
                @endforeach
                </div>
            </div>
        </div>
    @endforeach
</nav>

<div class="ag-user">
    <div class="d-flex align-items-center gap-2">
        <span class="ag-user__avatar" aria-hidden="true">{{ auth()->user()->initials() }}</span>
        <span class="flex-grow-1 min-w-0">
            <span class="ag-user__name d-block text-truncate">{{ auth()->user()->name }}</span>
            <span class="ag-user__mail d-block text-truncate">{{ auth()->user()->roleLabel() }} · {{ auth()->user()->email }}</span>
        </span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-sm btn-link text-secondary p-1"
                    aria-label="Se déconnecter" data-bs-toggle="tooltip" title="Se déconnecter">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            </button>
        </form>
    </div>
</div>
