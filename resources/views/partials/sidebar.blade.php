@php $needsFix = $navNeedsFix ?? 0; @endphp

<a href="{{ route('dashboard') }}" class="ag-brand">
    <span class="ag-brand__mark" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
    <span>
        <span class="ag-brand__name d-block">{{ config('articleguard.name') }}</span>
        <span class="ag-brand__tagline">{{ config('articleguard.tagline') }}</span>
    </span>
</a>

<nav class="ag-nav" aria-label="Navigation principale">
    <p class="ag-nav__section">ArticleGuard</p>

    <a href="{{ route('dashboard') }}"
       class="ag-nav__link @if(request()->routeIs('dashboard')) is-active @endif"
       @if(request()->routeIs('dashboard')) aria-current="page" @endif>
        <i class="bi bi-grid-1x2" aria-hidden="true"></i> Dashboard
    </a>

    <a href="{{ route('articles.index') }}"
       class="ag-nav__link @if(request()->routeIs('articles.*')) is-active @endif"
       @if(request()->routeIs('articles.*')) aria-current="page" @endif>
        <i class="bi bi-file-text" aria-hidden="true"></i> Articles
        @if($needsFix > 0)
            <span class="ag-nav__badge" title="{{ $needsFix }} article(s) à corriger">{{ $needsFix }}</span>
        @endif
    </a>

    <a href="{{ route('audits.index') }}"
       class="ag-nav__link @if(request()->routeIs('audits.*')) is-active @endif"
       @if(request()->routeIs('audits.*')) aria-current="page" @endif>
        <i class="bi bi-clipboard-check" aria-hidden="true"></i> Audits
    </a>

    <a href="{{ route('sites.index') }}"
       class="ag-nav__link @if(request()->routeIs('sites.*')) is-active @endif"
       @if(request()->routeIs('sites.*')) aria-current="page" @endif>
        <i class="bi bi-globe2" aria-hidden="true"></i> Sites WordPress
    </a>

    <p class="ag-nav__section">Configuration</p>

    <a href="{{ route('settings.edit') }}"
       class="ag-nav__link @if(request()->routeIs('settings.*')) is-active @endif"
       @if(request()->routeIs('settings.*')) aria-current="page" @endif>
        <i class="bi bi-sliders" aria-hidden="true"></i> Paramètres
    </a>
</nav>

<div class="ag-user">
    <div class="d-flex align-items-center gap-2">
        <span class="ag-user__avatar" aria-hidden="true">{{ auth()->user()->initials() }}</span>
        <span class="flex-grow-1 min-w-0">
            <span class="ag-user__name d-block text-truncate">{{ auth()->user()->name }}</span>
            <span class="ag-user__mail d-block text-truncate">{{ auth()->user()->email }}</span>
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
