<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WordpressArticle;

/**
 * Un article est accessible via le site auquel il appartient.
 */
class WordpressArticlePolicy
{
    public function view(User $user, WordpressArticle $article): bool
    {
        return $this->owns($user, $article);
    }

    public function update(User $user, WordpressArticle $article): bool
    {
        return $this->owns($user, $article);
    }

    public function audit(User $user, WordpressArticle $article): bool
    {
        return $this->owns($user, $article);
    }

    protected function owns(User $user, WordpressArticle $article): bool
    {
        $site = $article->relationLoaded('site') ? $article->site : $article->site()->first();

        return $site !== null && $site->user_id === $user->id;
    }
}
