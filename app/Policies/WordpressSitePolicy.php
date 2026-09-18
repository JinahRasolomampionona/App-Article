<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WordpressSite;

/**
 * Un site n'est accessible qu'à l'utilisateur qui l'a connecté.
 */
class WordpressSitePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WordpressSite $site): bool
    {
        return $site->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, WordpressSite $site): bool
    {
        return $site->user_id === $user->id;
    }

    public function delete(User $user, WordpressSite $site): bool
    {
        return $site->user_id === $user->id;
    }

    public function sync(User $user, WordpressSite $site): bool
    {
        return $site->user_id === $user->id;
    }
}
