<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'wordpress_article_id',
        'status',
        'issues_count',
        'resolved_count',
        'content_hash',
        'trigger_source',
        'duration_ms',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'issues_count' => 'integer',
            'resolved_count' => 'integer',
            'duration_ms' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(WordpressArticle::class, 'wordpress_article_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ArticleAuditIssue::class);
    }
}
