<?php

namespace App\Models;

use App\Support\AgentCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Site assigné à un agent par l'Admin.
 *
 * « En cours » dès l'assignation : le site apparaît dans l'espace de l'agent,
 * sans connexion à faire de son côté. « Terminé » quand l'Admin clôt le
 * travail de l'agent : le site disparaît de son espace (la ligne reste pour
 * l'historique et les statistiques) jusqu'à ce que l'Admin le lui réassigne.
 */
class SiteAgentAssignment extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'wordpress_site_id',
        'user_id',
        'assigned_by',
        'status',
        'assigned_at',
        'completed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_IN_PROGRESS,
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // La liste des agents par site en dépend.
        static::saved(fn () => AgentCatalog::flush());
        static::deleted(fn () => AgentCatalog::flush());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WordpressSite::class, 'wordpress_site_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function statusLabel(): string
    {
        return $this->isDone() ? 'Terminé' : 'En cours';
    }

    public function statusVariant(): string
    {
        return $this->isDone() ? 'success' : 'primary';
    }
}
