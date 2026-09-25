<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte désactivé par l'Admin perd l'accès immédiatement, y compris avec
 * une session déjà ouverte.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Votre compte a été désactivé. Contactez un administrateur.';

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message], 401)
                : redirect()->route('login')->with('error', $message);
        }

        return $next($request);
    }
}
