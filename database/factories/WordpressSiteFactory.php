<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WordpressSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WordpressSite>
 */
class WordpressSiteFactory extends Factory
{
    protected $model = WordpressSite::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Site de test',
            'url' => 'https://example.com',
            'wp_username' => 'editeur',
            // Format réel d'une Application Password WordPress : 24 caractères.
            'application_password' => 'abcd1234abcd1234abcd1234',
            'connection_status' => WordpressSite::STATUS_CONNECTED,
            'sync_status' => 'idle',
        ];
    }

    /**
     * Site sans credentials : lecture seule.
     */
    public function readOnly(): static
    {
        return $this->state(fn () => [
            'wp_username' => null,
            'application_password' => null,
        ]);
    }
}
