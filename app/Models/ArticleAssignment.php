<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une prise en charge d'article par un agent : de la prise à la libération.
 *
 * Sert l'historique de l'Admin (qui a travaillé sur quoi, combien de temps) et
 * permet d'attribuer une correction à l'agent qui l'a faite, même lorsque
 * l'audit réseau confirme le résultat après la libération de l'article.
 */
class ArticleAssignment extends Model
{
    public const REASON_RELEASED = 'released';
    public const REASON_COMPLETED = 'completed';
    public const REASON_EXPIRED = 'expired';
    public const REASON_REASSIGNED = 'reassigned';

    protected $fillable = [
        'wordpress_article_id',
        'wordpress_site_id',
        'user_id',
        'agent_name',
        'assigned_by',
        'taken_at',
        'released_at',
        'release_reason',
        'completed_at',
        'audit_result',
        'issues_remaining',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'released_at' => 'datetime',
            'completed_at' => 'datetime',
            'issues_remaining' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(WordpressArticle::class, 'wordpress_article_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WordpressSite::class, 'wordpress_site_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->released_at === null;
    }

    public function releaseLabel(): string
    {
        return match ($this->release_reason) {
            self::REASON_COMPLETED => 'Correction terminée',
            self::REASON_EXPIRED => 'Verrou expiré',
            self::REASON_REASSIGNED => 'Réassigné',
            self::REASON_RELEASED => 'Libéré',
            default => 'En cours',
        };
    }
}
