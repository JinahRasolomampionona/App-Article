<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WordpressCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'wordpress_site_id',
        'wp_id',
        'name',
        'slug',
        'parent_wp_id',
        'posts_count',
    ];

    protected function casts(): array
    {
        return [
            'wp_id' => 'integer',
            'parent_wp_id' => 'integer',
            'posts_count' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WordpressSite::class, 'wordpress_site_id');
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(
            WordpressArticle::class,
            'article_category',
            'wordpress_category_id',
            'wordpress_article_id'
        );
    }
}
