<?php

namespace App\Policies;

use App\Models\User;

/**
 * Gestion des comptes : réservée à l'Admin, qui ne peut ni se supprimer ni
 * se désactiver lui-même — l'espace resterait sans administrateur.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function changeRole(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    public function delete(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    /** Statistiques d'un compte : les siennes, ou toutes pour l'Admin. */
    public function viewStatistics(User $user, User $target): bool
    {
        return $user->isAdmin() || $user->id === $target->id;
    }
}
