<?php

namespace App\Models;

use App\Support\AgentCatalog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Gestion de l'espace de travail, des comptes et des statistiques globales. */
    public const ROLE_ADMIN = 'admin';

    /** Correction des articles qu'il a pris en charge. */
    public const ROLE_AGENT = 'agent';

    /**
     * `role` et `is_active` sont volontairement absents : ils ne se posent
     * qu'explicitement (forceFill), jamais depuis une saisie transmise en bloc
     * — un agent ne doit pas pouvoir s'attribuer le rôle Admin.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // La liste des agents est mémorisée pour la requête : toute
        // modification d'un compte l'invalide.
        static::saved(fn () => AgentCatalog::flush());
        static::deleted(fn () => AgentCatalog::flush());
    }

    public function sites(): HasMany
    {
        return $this->hasMany(WordpressSite::class);
    }

    /** Connexions WordPress du compte : un site par connexion. */
    public function siteConnections(): HasMany
    {
        return $this->hasMany(SiteConnection::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /** Articles actuellement pris en charge (verrou non expiré). */
    public function lockedArticles(): HasMany
    {
        return $this->hasMany(WordpressArticle::class, 'assigned_to')
            ->where('lock_expires_at', '>', now());
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ArticleAssignment::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isAgent(): bool
    {
        return $this->role !== self::ROLE_ADMIN;
    }

    public function roleLabel(): string
    {
        return $this->isAdmin() ? 'Admin' : 'Agent';
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeAgents(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_AGENT);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Initiales affichées dans l'avatar de la sidebar.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return mb_strtoupper(mb_substr((string) $this->email, 0, 2));
        }

        $initials = mb_substr($parts[0], 0, 1);

        if (count($parts) > 1) {
            $initials .= mb_substr($parts[count($parts) - 1], 0, 1);
        }

        return mb_strtoupper($initials);
    }
}
