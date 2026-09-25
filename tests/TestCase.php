<?php

namespace Tests;

use App\Models\SiteConnection;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Connecte `$user` au site avec ses propres identifiants WordPress.
     */
    protected function connectSite(User $user, WordpressSite $site, array $attributes = []): SiteConnection
    {
        return SiteConnection::create(array_merge([
            'wordpress_site_id' => $site->id,
            'user_id' => $user->id,
            'wp_username' => strtolower($user->name ?: 'agent'),
            'application_password' => 'abcd1234abcd1234abcd1234',
            'connection_status' => WordpressSite::STATUS_CONNECTED,
        ], $attributes));
    }

    /**
     * Pose directement le verrou de traitement d'un article, comme si
     * `$user` venait de le prendre.
     */
    protected function lockFor(WordpressArticle $article, User $user, int $minutes = 30): WordpressArticle
    {
        $article->forceFill([
            'assigned_to' => $user->id,
            'locked_at' => now(),
            'lock_expires_at' => now()->addMinutes($minutes),
        ])->save();

        return $article->refresh();
    }
}
