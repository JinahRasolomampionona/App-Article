<?php

namespace Database\Factories;

use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WordpressArticle>
 */
class WordpressArticleFactory extends Factory
{
    protected $model = WordpressArticle::class;

    public function definition(): array
    {
        $slug = $this->faker->unique()->slug();

        return [
            'wordpress_site_id' => WordpressSite::factory(),
            'wp_id' => $this->faker->unique()->numberBetween(1, 100000),
            'title' => 'Guide des bagues',
            'slug' => $slug,
            'link' => 'https://example.com/'.$slug,
            'content' => '<h1>Introduction</h1><p>Un contenu.</p><img src="https://example.com/i.jpg" alt="Bague">',
            'excerpt' => 'Un extrait.',
            'featured_media_id' => 12,
            'featured_media_url' => 'https://example.com/featured.jpg',
            'status' => 'publish',
            'wordpress_published_at' => now()->subDays(3),
            'wordpress_modified_at' => now()->subDay(),
            'synced_at' => now(),
            'audit_status' => WordpressArticle::AUDIT_PENDING,
        ];
    }

    public function withoutFeaturedImage(): static
    {
        return $this->state(fn () => [
            'featured_media_id' => 0,
            'featured_media_url' => null,
        ]);
    }

    public function withContent(string $content): static
    {
        return $this->state(fn () => ['content' => $content]);
    }
}
