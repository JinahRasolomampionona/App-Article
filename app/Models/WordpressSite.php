<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WordpressSite extends Model
{
    use HasFactory;

    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_AUTH_FAILED = 'auth_failed';
    public const STATUS_UNREACHABLE = 'unreachable';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'user_id',
        'name',
        'url',
        'wp_username',
        'wp_can_edit',
        'wp_role',
        'application_password',
        'connection_status',
        'connection_message',
        'last_checked_at',
        'last_sync_at',
        'sync_status',
        'sync_message',
    ];

    protected $hidden = [
        'application_password',
    ];

    /**
     * Attributs portés par la connexion du compte (SiteConnection) et non par
     * le site : chaque compte connecte le site avec ses propres identifiants.
     *
     * Ils restent lisibles et modifiables comme des attributs du site
     * (`$site->wp_username`, `forceFill(['connection_status' => …])->save()`) :
     * lecture et écriture sont redirigées vers la connexion active, enregistrée
     * avec le site.
     */
    public const CONNECTION_ATTRIBUTES = [
        'wp_username',
        'application_password',
        'wp_can_edit',
        'wp_role',
        'connection_status',
        'connection_message',
        'last_checked_at',
    ];

    /** Connexion utilisée pour les appels WordPress de cette instance. */
    protected ?SiteConnection $activeConnection = null;

    protected function casts(): array
    {
        return [
            'last_sync_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // La connexion suit le site : créée avec lui pour son créateur, et
        // enregistrée à chaque sauvegarde si elle a été modifiée.
        static::saved(function (WordpressSite $site) {
            $connection = $site->activeConnection;

            if ($connection === null && $site->wasRecentlyCreated) {
                $connection = $site->activeConnection = new SiteConnection;
            }

            if ($connection === null || ($connection->exists && ! $connection->isDirty())) {
                return;
            }

            $connection->wordpress_site_id ??= $site->id;
            $connection->user_id ??= $site->user_id;
            $connection->save();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(SiteConnection::class);
    }

    /* --- Connexion active ------------------------------------------------------ */

    /**
     * Connexion utilisée pour parler à WordPress.
     *
     * Par ordre de préférence : celle explicitement choisie ; celle du compte
     * connecté ; celle du créateur du site ; toute connexion valide. Un Admin
     * qui consulte le site d'un agent sans l'avoir connecté lui-même passe donc
     * par la connexion de cet agent.
     */
    public function connection(): SiteConnection
    {
        if ($this->activeConnection !== null) {
            return $this->activeConnection;
        }

        if (! $this->exists) {
            return $this->activeConnection = new SiteConnection;
        }

        $connections = $this->relationLoaded('connections') ? $this->connections : $this->connections()->get();
        $userId = auth()->id();

        $connection = ($userId ? $connections->firstWhere('user_id', $userId) : null)
            ?? $connections->first(fn (SiteConnection $c) => $c->user_id === $this->user_id && $c->hasCredentials())
            ?? $connections->first(fn (SiteConnection $c) => $c->connection_status === self::STATUS_CONNECTED && $c->hasCredentials())
            ?? $connections->first(fn (SiteConnection $c) => $c->hasCredentials())
            ?? $connections->first();

        return $this->activeConnection = $connection ?? new SiteConnection([
            'wordpress_site_id' => $this->id,
            'user_id' => $userId ?? $this->user_id,
        ]);
    }

    /** Force la connexion d'un compte donné (jobs en file, où personne n'est connecté). */
    public function useConnectionOf(?int $userId): static
    {
        if ($userId !== null) {
            $connection = $this->connections()->where('user_id', $userId)->first();

            if ($connection !== null) {
                $this->activeConnection = $connection;
            }
        }

        return $this;
    }

    public function useConnection(SiteConnection $connection): static
    {
        $this->activeConnection = $connection;

        return $this;
    }

    /** Connexion propre à un compte, s'il a connecté ce site. */
    public function connectionOf(User $user): ?SiteConnection
    {
        return $this->connections()->where('user_id', $user->id)->first();
    }

    public function refresh()
    {
        $this->activeConnection = null;

        return parent::refresh();
    }

    /**
     * Sites accessibles à un compte : tous pour l'Admin, ceux qu'il a
     * connectés pour un Agent.
     *
     * @param  Builder<WordpressSite>  $query
     * @return Builder<WordpressSite>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereHas('connections', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    public function isAccessibleBy(User $user): bool
    {
        return $user->isAdmin() || $this->connections()->where('user_id', $user->id)->exists();
    }

    /* Attributs délégués à la connexion active (voir CONNECTION_ATTRIBUTES). */

    public function getWpUsernameAttribute(): mixed { return $this->connection()->wp_username; }
    public function setWpUsernameAttribute(mixed $value): void { $this->connection()->wp_username = $value; }

    public function getApplicationPasswordAttribute(): mixed { return $this->connection()->application_password; }
    public function setApplicationPasswordAttribute(mixed $value): void { $this->connection()->application_password = $value; }

    public function getWpCanEditAttribute(): mixed { return $this->connection()->wp_can_edit; }
    public function setWpCanEditAttribute(mixed $value): void { $this->connection()->wp_can_edit = $value; }

    public function getWpRoleAttribute(): mixed { return $this->connection()->wp_role; }
    public function setWpRoleAttribute(mixed $value): void { $this->connection()->wp_role = $value; }

    public function getConnectionStatusAttribute(): mixed { return $this->connection()->connection_status; }
    public function setConnectionStatusAttribute(mixed $value): void { $this->connection()->connection_status = $value; }

    public function getConnectionMessageAttribute(): mixed { return $this->connection()->connection_message; }
    public function setConnectionMessageAttribute(mixed $value): void { $this->connection()->connection_message = $value; }

    public function getLastCheckedAtAttribute(): mixed { return $this->connection()->last_checked_at; }
    public function setLastCheckedAtAttribute(mixed $value): void { $this->connection()->last_checked_at = $value; }

    public function articles(): HasMany
    {
        return $this->hasMany(WordpressArticle::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(WordpressCategory::class);
    }

    /**
     * Le site dispose-t-il de credentials permettant l'écriture sur WordPress ?
     */
    public function hasCredentials(): bool
    {
        return filled($this->wp_username) && filled($this->application_password);
    }

    /**
     * Le compte WordPress enregistré peut-il réellement modifier les articles ?
     *
     * Être authentifié ne suffit pas : un compte « abonné » lit parfaitement
     * l'API REST sans avoir le droit de demander `context=edit` ni
     * `status=any`. Tant que le test de connexion n'a pas tranché
     * (`wp_can_edit` à null), on suppose le cas favorable, quitte à se rabattre
     * automatiquement en lecture seule si WordPress refuse.
     */
    public function canEditContent(): bool
    {
        return $this->hasCredentials() && $this->wp_can_edit !== false;
    }

    /**
     * Une synchronisation est-elle effectivement en cours d'exécution ?
     *
     * `running` est posé par le worker ou par `wp:sync` au démarrage : quelqu'un
     * traite déjà le site. Passé un délai raisonnable sans nouvelle, l'état est
     * considéré comme abandonné (processus tué) pour ne pas bloquer une relance.
     */
    public function isSyncRunning(): bool
    {
        return $this->sync_status === 'running'
            && $this->updated_at !== null
            && $this->updated_at->gt(now()->subMinutes(30));
    }

    /**
     * Compte authentifié mais sans droit d'écriture : les articles publiés
     * restent consultables, les modifications seront refusées par WordPress.
     */
    public function isReadOnlyAccount(): bool
    {
        return $this->hasCredentials() && $this->wp_can_edit === false;
    }

    /**
     * Mémorise le constat « ce compte ne peut pas éditer », pour ne pas
     * retenter à chaque page de synchronisation une requête déjà refusée.
     */
    public function markReadOnlyAccount(): void
    {
        if ($this->wp_can_edit === false) {
            return;
        }

        $this->forceFill(['wp_can_edit' => false])->save();
    }

    /**
     * Nettoie une Application Password saisie par l'utilisateur.
     *
     * WordPress l'affiche par groupes de quatre caractères
     * (« abcd EFGH ijkl MNOP qrst uvwx ») : ces espaces sont purement
     * décoratifs. Les espaces insécables collés par un copier-coller depuis
     * l'admin WordPress sont également retirés, sans quoi l'en-tête
     * `Authorization` transporte une valeur invalide.
     */
    public static function normalizeApplicationPassword(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\s\x{00A0}]+/u', '', $value) ?? '';

        return $value === '' ? null : $value;
    }

    /**
     * L'Application Password enregistrée a-t-elle le format généré par
     * WordPress (24 caractères alphanumériques) ?
     *
     * Renvoie `false` notamment lorsque l'utilisateur a saisi le mot de passe
     * de son compte wp-admin : l'API REST ne l'acceptera jamais. Le contrôle
     * reste indicatif — un plugin d'authentification tiers peut utiliser un
     * autre format.
     */
    public function applicationPasswordLooksLikeWordPress(): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{24}$/', (string) $this->application_password);
    }

    /**
     * Masque l'Application Password : jamais renvoyée en clair à l'interface.
     */
    public function maskedApplicationPassword(): ?string
    {
        if (! filled($this->application_password)) {
            return null;
        }

        return str_repeat('•', 20).' '.mb_substr((string) $this->application_password, -4);
    }

    public function isConnected(): bool
    {
        return $this->connection_status === self::STATUS_CONNECTED;
    }

    public function statusLabel(): string
    {
        return match ($this->connection_status) {
            self::STATUS_CONNECTED => 'Connecté',
            self::STATUS_AUTH_FAILED => 'Authentification refusée',
            self::STATUS_UNREACHABLE => 'Injoignable',
            self::STATUS_ERROR => 'Erreur',
            default => 'Non testé',
        };
    }

    public function statusVariant(): string
    {
        return match ($this->connection_status) {
            self::STATUS_CONNECTED => 'success',
            self::STATUS_AUTH_FAILED, self::STATUS_ERROR => 'danger',
            self::STATUS_UNREACHABLE => 'warning',
            default => 'muted',
        };
    }
}
