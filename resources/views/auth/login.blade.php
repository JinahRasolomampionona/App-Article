@extends('layouts.guest')

@section('title', 'Connexion')

@section('content')
    <h2 class="h6 fw-semibold mb-3">Connexion</h2>

    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Adresse e-mail</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Mot de passe</label>
            <div class="input-group has-validation">
                <input type="password" id="password" name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       required autocomplete="current-password">
                <button type="button" class="btn btn-outline-secondary"
                        data-password-toggle="password"
                        aria-label="Afficher le mot de passe" aria-pressed="false">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
            <label class="form-check-label small" for="remember">Rester connecté</label>
        </div>

        <button type="submit" class="btn btn-primary w-100">Se connecter</button>
    </form>
@endsection

@section('footer')
    Pas encore de compte ? <a href="{{ route('register') }}">Créer un compte</a>
@endsection
