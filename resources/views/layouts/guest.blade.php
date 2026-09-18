<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title') · {{ config('articleguard.name') }}</title>

    <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="%236c4ce8"/><path d="M16 7l7 3v6c0 4.4-2.9 7.7-7 9-4.1-1.3-7-4.6-7-9v-6l7-3z" fill="white"/></svg>') }}">

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="d-flex align-items-center" style="min-height: 100vh;">

<main class="container" style="max-width: 27rem;">
    <div class="text-center mb-4">
        <span class="ag-brand__mark mx-auto mb-3" aria-hidden="true" style="width:44px;height:44px;font-size:1.3rem;">
            <i class="bi bi-shield-check"></i>
        </span>
        <h1 class="h4 fw-semibold mb-1">{{ config('articleguard.name') }}</h1>
        <p class="ag-muted small mb-0">{{ config('articleguard.tagline') }}</p>
    </div>

    <div class="ag-card">
        <div class="ag-card__body">
            @include('partials.flash')
            @yield('content')
        </div>
    </div>

    <p class="text-center ag-hint mt-3 mb-0">@yield('footer')</p>
</main>

</body>
</html>
