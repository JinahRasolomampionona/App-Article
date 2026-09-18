<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAuditIssue extends Model
{
    use HasFactory;

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';

    protected $fillable = [
        'wordpress_article_id',
        'article_audit_id',
        'rule_type',
        'severity',
        'message',
        'metadata',
        'detected_at',
        'resolved_at',
        'resolved_manually',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolved_manually' => 'boolean',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(WordpressArticle::class, 'wordpress_article_id');
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(ArticleAudit::class, 'article_audit_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function severityVariant(): string
    {
        return match ($this->severity) {
            self::SEVERITY_ERROR => 'danger',
            self::SEVERITY_INFO => 'info',
            default => 'warning',
        };
    }
}
