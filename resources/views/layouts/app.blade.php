<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Tableau de bord') · {{ config('articleguard.name') }}</title>

    <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="%236c4ce8"/><path d="M16 7l7 3v6c0 4.4-2.9 7.7-7 9-4.1-1.3-7-4.6-7-9v-6l7-3z" fill="white"/></svg>') }}">

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
<div class="ag-shell">

    {{-- Sidebar fixe (desktop) --}}
    <aside class="ag-sidebar">
        @include('partials.sidebar')
    </aside>

    {{-- Sidebar en offcanvas (mobile / tablette) --}}
    <div class="offcanvas offcanvas-start" tabindex="-1" id="ag-sidebar-offcanvas"
         aria-label="Navigation principale" style="width: 268px;">
        <div class="offcanvas-body p-0 d-flex flex-column">
            @include('partials.sidebar')
        </div>
    </div>

    <div class="ag-main">
        <header class="ag-header">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#ag-sidebar-offcanvas"
                    aria-controls="ag-sidebar-offcanvas" aria-label="Ouvrir le menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>

            <nav class="ag-breadcrumb d-none d-md-block" aria-label="Fil d'Ariane">
                @yield('breadcrumb')
            </nav>

            <div class="ms-auto d-flex align-items-center gap-2">
                @include('partials.site-switcher')

                <div class="dropdown d-lg-none">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"
                            aria-expanded="false" aria-label="Menu utilisateur">
                        <i class="bi bi-person" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small">{{ auth()->user()->email }}</span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="dropdown-item" type="submit">Se déconnecter</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="ag-content">
            @include('partials.flash')
            @include('partials.queue-warning')

            <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
                <div class="flex-grow-1">
                    <h1 class="ag-page-title">@yield('heading', 'ArticleGuard')</h1>
                    @hasSection('subheading')
                        <p class="ag-page-subtitle">@yield('subheading')</p>
                    @endif
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @yield('actions')
                </div>
            </div>

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
