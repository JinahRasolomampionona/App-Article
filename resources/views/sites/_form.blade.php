@php $site = $site ?? null; @endphp

<div class="mb-3">
    <label for="name" class="form-label">Nom du site</label>
    <input type="text" id="name" name="name" value="{{ old('name', $site?->name) }}"
           class="form-control @error('name') is-invalid @enderror"
           required placeholder="Bijouteries" autofocus>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="url" class="form-label">Domaine / URL</label>
    <input type="text" id="url" name="url" value="{{ old('url', $site?->url) }}"
           class="form-control @error('url') is-invalid @enderror"
           required placeholder="https://exemple.com" aria-describedby="url-hint">
    <div class="ag-hint mt-1" id="url-hint">
        L’API REST doit être accessible sur <span class="ag-mono">/wp-json/wp/v2</span>.
        Les adresses internes et privées sont refusées pour des raisons de sécurité.
    </div>
    @error('url')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<hr class="my-4">

<h3 class="h6 fw-semibold mb-1">Authentification WordPress</h3>
<p class="ag-hint mb-2">
    Nécessaire pour modifier les articles. Sans ces informations, ArticleGuard reste en lecture seule.
</p>

{{-- Aide repliée par défaut : utile une fois, encombrante ensuite. --}}
<details class="ag-disclosure small mb-3">
    <summary class="ag-disclosure__summary">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        Comment obtenir une Application Password&nbsp;?
        <i class="bi bi-chevron-down ag-disclosure__chevron ms-auto" aria-hidden="true"></i>
    </summary>
    <div class="ag-disclosure__body">
    <p class="fw-semibold mb-1">L’API REST n’accepte pas le mot de passe de votre compte wp-admin.</p>
    <p class="mb-1">Il faut une <strong>Application Password</strong> dédiée :</p>
    <ol class="mb-1 ps-3">
        <li>connectez-vous à WordPress&nbsp;;</li>
        <li>ouvrez <em>Utilisateurs → Profil</em>&nbsp;;</li>
        <li>section <em>Application Passwords</em>, nommez-la « ArticleGuard » puis <em>Add New</em>&nbsp;;</li>
        <li>copiez la valeur affichée (24 caractères, du type
            <span class="ag-mono">xxxx xxxx xxxx xxxx xxxx xxxx</span>) : elle n’est montrée qu’une seule fois.</li>
    </ol>
    <p class="mb-0">
        L’identifiant reste celui de votre compte WordPress (nom d’utilisateur ou e-mail).
        @if($site?->url)
            Raccourci :
            <a href="{{ rtrim($site->url, '/') }}/wp-admin/profile.php#application-passwords" target="_blank" rel="noopener noreferrer">profil WordPress de {{ parse_url($site->url, PHP_URL_HOST) }}</a>.
        @endif
    </p>
    </div>
</details>

<div class="mb-3">
    <label for="wp_username" class="form-label">Identifiant WordPress</label>
    <input type="text" id="wp_username" name="wp_username" value="{{ old('wp_username', $site?->wp_username) }}"
           class="form-control @error('wp_username') is-invalid @enderror"
           placeholder="admin" autocomplete="off">
    @error('wp_username')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="application_password" class="form-label">Application Password</label>
    <input type="password" id="application_password" name="application_password"
           class="form-control @error('application_password') is-invalid @enderror"
           placeholder="{{ $site?->hasCredentials() ? 'Laisser vide pour conserver la valeur actuelle' : 'xxxx xxxx xxxx xxxx xxxx xxxx' }}"
           autocomplete="new-password" aria-describedby="app-password-hint">
    <div class="ag-hint mt-1" id="app-password-hint">
        @if($site?->hasCredentials())
            Enregistrée : <span class="ag-mono">{{ $site->maskedApplicationPassword() }}</span>.
            Laissez le champ vide pour la conserver.
        @else
            Stockée chiffrée et jamais réaffichée en clair.
        @endif
    </div>
    @error('application_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
