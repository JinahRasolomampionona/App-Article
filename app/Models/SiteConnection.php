<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Connexion d'un compte (Admin ou Agent) à un site WordPress, avec ses propres
 * identifiants. Le site et ses articles sont partagés ; la connexion, elle,
 * appartient au compte.
 */
class SiteConnection extends Model
{
    protected $fillable = [
        'wordpress_site_id',
        'user_id',
        'wp_username',
        'application_password',
        'wp_can_edit',
        'wp_role',
        'connection_status',
        'connection_message',
        'last_checked_at',
    ];

    protected $hidden = [
        'application_password',
    ];

    protected $attributes = [
        'connection_status' => WordpressSite::STATUS_UNKNOWN,
    ];

    protected function casts(): array
    {
        return [
            // Chiffrement transparent par Laravel : la valeur en base est illisible.
            'application_password' => 'encrypted',
            'wp_can_edit' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // La liste des agents par site en dépend.
        static::saved(fn () => \App\Support\AgentCatalog::flush());
        static::deleted(fn () => \App\Support\AgentCatalog::flush());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WordpressSite::class, 'wordpress_site_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasCredentials(): bool
    {
        return filled($this->wp_username) && filled($this->application_password);
    }
}
