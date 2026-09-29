<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commentaire de l'Admin sur un article : ce que l'agent doit encore
 * modifier. Affiché à l'agent dans le tableau et dans l'éditeur tant qu'il
 * traite l'article.
 */
class ArticleNote extends Model
{
    protected $fillable = [
        'wordpress_article_id',
        'author_id',
        'agent_id',
        'body',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(WordpressArticle::class, 'wordpress_article_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
