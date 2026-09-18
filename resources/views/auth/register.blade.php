@extends('layouts.guest')

@section('title', 'Créer un compte')

@section('content')
    <h2 class="h6 fw-semibold mb-3">Créer un compte</h2>

    <form method="POST" action="{{ route('register') }}" novalidate>
        @csrf

        <div class="mb-3">
            <label for="name" class="form-label">Nom</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}"
                   class="form-control @error('name') is-invalid @enderror"
                   required autofocus autocomplete="name">
            @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Adresse e-mail</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Mot de passe</label>
            <div class="input-group has-validation">
                <input type="password" id="password" name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       required autocomplete="new-password" aria-describedby="password-hint">
                <button type="button" class="btn btn-outline-secondary"
                        data-password-toggle="password"
                        aria-label="Afficher le mot de passe" aria-pressed="false">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="ag-hint mt-1" id="password-hint">8 caractères minimum, avec au moins une lettre et un chiffre.</div>
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirmer le mot de passe</label>
            <div class="input-group">
                <input type="password" id="password_confirmation" name="password_confirmation"
                       class="form-control" required autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary"
                        data-password-toggle="password_confirmation"
                        aria-label="Afficher le mot de passe" aria-pressed="false">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100">Créer mon compte</button>
    </form>
@endsection

@section('footer')
    Vous avez déjà un compte ? <a href="{{ route('login') }}">Se connecter</a>
@endsection
