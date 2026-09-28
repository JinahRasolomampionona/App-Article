<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identifiants WordPress d'un site (une ligne par site), saisis par l'Admin.
 *
 * L'Application Password est chiffrée par Laravel et n'est jamais réaffichée
 * en clair. Les agents assignés au site travaillent à travers ces
 * identifiants : ils n'ont rien à connecter eux-mêmes.
 */
class SiteCredential extends Model
{
    protected $fillable = [
        'wordpress_site_id',
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(WordpressSite::class, 'wordpress_site_id');
    }

    public function hasCredentials(): bool
    {
        return filled($this->wp_username) && filled($this->application_password);
    }
}
