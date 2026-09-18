<?php

namespace Database\Factories;

use App\Models\WordpressCategory;
use App\Models\WordpressSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WordpressCategory>
 */
class WordpressCategoryFactory extends Factory
{
    protected $model = WordpressCategory::class;

    public function definition(): array
    {
        return [
            'wordpress_site_id' => WordpressSite::factory(),
            'wp_id' => $this->faker->unique()->numberBetween(1, 10000),
            'name' => $this->faker->randomElement(['Bagues', 'Colliers', 'Bracelets', 'Médailles']),
            'slug' => $this->faker->unique()->slug(1),
            'parent_wp_id' => 0,
            'posts_count' => $this->faker->numberBetween(0, 40),
        ];
    }
}
