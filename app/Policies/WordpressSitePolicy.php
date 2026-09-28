<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WordpressSite;

/**
 * L'Admin connecte et gère les sites (tester, modifier, supprimer, assigner
 * aux agents). Un agent voit les sites qui lui sont assignés et peut les
 * synchroniser : il n'a rien à connecter lui-même.
 */
class WordpressSitePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WordpressSite $site): bool
    {
        return $site->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, WordpressSite $site): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, WordpressSite $site): bool
    {
        return $user->isAdmin();
    }

    /** Test de connexion : il révèle l'état des identifiants. */
    public function test(User $user, WordpressSite $site): bool
    {
        return $user->isAdmin();
    }

    /** Assigner le site aux agents, clore ou rouvrir une assignation. */
    public function assign(User $user, WordpressSite $site): bool
    {
        return $user->isAdmin();
    }

    public function sync(User $user, WordpressSite $site): bool
    {
        return $site->isAccessibleBy($user);
    }

    /**
     * Médiathèque : un agent qui corrige un article doit pouvoir choisir,
     * téléverser une image ou renseigner son texte alternatif.
     */
    public function manageMedia(User $user, WordpressSite $site): bool
    {
        return $site->isAccessibleBy($user);
    }
}
