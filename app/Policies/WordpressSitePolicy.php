<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WordpressSite;

/**
 * Chaque compte connecte ses propres sites, avec ses propres identifiants
 * WordPress. Un site (et ses articles) est partagé entre les comptes qui l'ont
 * connecté ; l'Admin voit tous les sites pour suivre le travail des agents.
 *
 * - voir, synchroniser, utiliser la médiathèque : avoir accès au site ;
 * - tester, modifier, supprimer : avoir sa propre connexion au site — chacun
 *   gère ses identifiants, personne ne touche à ceux d'un autre.
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
        return true;
    }

    public function update(User $user, WordpressSite $site): bool
    {
        return $this->connected($user, $site);
    }

    public function delete(User $user, WordpressSite $site): bool
    {
        return $this->connected($user, $site);
    }

    public function test(User $user, WordpressSite $site): bool
    {
        return $this->connected($user, $site);
    }

    public function sync(User $user, WordpressSite $site): bool
    {
        return $site->isAccessibleBy($user);
    }

    public function manageMedia(User $user, WordpressSite $site): bool
    {
        return $site->isAccessibleBy($user);
    }

    protected function connected(User $user, WordpressSite $site): bool
    {
        return $site->connections()->where('user_id', $user->id)->exists();
    }
}
