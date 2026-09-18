<?php

namespace App\Models;

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

    protected function casts(): array
    {
        return [
            // Chiffrement transparent par Laravel : la valeur en base est illisible.
            'application_password' => 'encrypted',
            'wp_can_edit' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
