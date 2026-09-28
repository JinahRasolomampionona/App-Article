<?php

namespace Tests;

use App\Models\SiteAgentAssignment;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Assigne le site à `$user`, comme le ferait l'Admin.
     */
    protected function assignSite(User $user, WordpressSite $site, string $status = SiteAgentAssignment::STATUS_IN_PROGRESS): SiteAgentAssignment
    {
        return SiteAgentAssignment::create([
            'wordpress_site_id' => $site->id,
            'user_id' => $user->id,
            'status' => $status,
            'assigned_at' => now(),
        ]);
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
