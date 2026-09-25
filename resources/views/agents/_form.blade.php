@php
    /** @var \App\Models\User|null $agent */
    $canChangeRole = ! $agent || auth()->user()->can('changeRole', $agent);
@endphp

<div class="mb-3">
    <label for="name" class="form-label">Nom</label>
    <input type="text" id="name" name="name" value="{{ old('name', $agent?->name) }}"
           class="form-control @error('name') is-invalid @enderror" required maxlength="120"
           placeholder="Ex. Daniella">
    <div class="form-text">Affiché dans le tableau des articles : « En cours par {{ $agent?->name ?? 'Daniella' }} ».</div>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="email" class="form-label">Adresse e-mail</label>
    <input type="email" id="email" name="email" value="{{ old('email', $agent?->email) }}"
           class="form-control @error('email') is-invalid @enderror" required autocomplete="off">
    <div class="form-text">Identifiant de connexion.</div>
    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label for="password" class="form-label">Mot de passe</label>
        <div class="input-group has-validation">
            <input type="password" id="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   autocomplete="new-password" @if(! $agent) required @endif>
            <button type="button" class="btn btn-outline-secondary"
                    data-password-toggle="password"
                    aria-label="Afficher le mot de passe" aria-pressed="false">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="form-text">
            @if($agent)
                Laisser vide pour conserver le mot de passe actuel.
            @else
                8 caractères minimum, lettres et chiffres.
            @endif
        </div>
    </div>
    <div class="col-md-6">
        <label for="password_confirmation" class="form-label">Confirmation</label>
        <div class="input-group">
            <input type="password" id="password_confirmation" name="password_confirmation"
                   class="form-control" autocomplete="new-password">
            <button type="button" class="btn btn-outline-secondary"
                    data-password-toggle="password_confirmation"
                    aria-label="Afficher le mot de passe" aria-pressed="false">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</div>

@if($canChangeRole)
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label for="role" class="form-label">Rôle</label>
            <select id="role" name="role" class="form-select @error('role') is-invalid @enderror">
                <option value="agent" @selected(old('role', $agent?->role ?? 'agent') === 'agent')>Agent — corrige les articles qu’il prend</option>
                <option value="admin" @selected(old('role', $agent?->role) === 'admin')>Admin — gère les comptes, sites et statistiques</option>
            </select>
            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        @if($agent)
            <div class="col-md-6 d-flex align-items-end">
                <input type="hidden" name="is_active" value="0">
                <label class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1"
                           @checked(old('is_active', $agent->is_active))>
                    <span class="form-check-label small">Compte actif</span>
                </label>
            </div>
        @endif
    </div>
    @if($agent)
        <p class="ag-hint mb-3">Désactiver un compte bloque sa connexion et libère ses articles en cours.</p>
    @endif
@else
    <p class="ag-hint mb-3">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Vous ne pouvez pas modifier votre propre rôle ni désactiver votre compte.
    </p>
@endif
