<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Inscription.
 *
 * Dans l'espace partagé, les comptes des agents sont créés par l'Admin :
 * l'inscription publique n'est ouverte que pour le tout premier compte, qui
 * devient Admin, ou si `AG_OPEN_REGISTRATION` l'autorise (le compte créé est
 * alors un simple Agent).
 */
class RegisteredUserController extends Controller
{
    public static function registrationOpen(): bool
    {
        return config('articleguard.open_registration') || ! User::query()->exists();
    }

    public function create(): View|RedirectResponse
    {
        if (! self::registrationOpen()) {
            return $this->closed();
        }

        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        if (! self::registrationOpen()) {
            return $this->closed();
        }

        $user = DB::transaction(function () use ($request) {
            // Le mot de passe est haché par le cast `hashed` du modèle User.
            $user = new User($request->safe()->only('name', 'email', 'password'));

            // Le rôle n'est jamais lu depuis la requête.
            $user->forceFill([
                'role' => User::query()->lockForUpdate()->exists() ? User::ROLE_AGENT : User::ROLE_ADMIN,
                'is_active' => true,
            ])->save();

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('status', $user->isAdmin()
                ? 'Bienvenue sur ArticleGuard WP. Connectez un premier site, puis créez les comptes de vos agents.'
                : 'Bienvenue sur ArticleGuard WP.');
    }

    protected function closed(): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->with('error', 'Les comptes sont créés par un administrateur. Demandez-lui vos identifiants.');
    }
}
