<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * Attributs portés par les identifiants du site (table `site_credentials`)
     * et non par la table des sites : l'Application Password est stockée à part.
     *
     * Ils restent lisibles et modifiables comme des attributs du site
     * (`$site->wp_username`, `forceFill(['connection_status' => …])->save()`) :
     * lecture et écriture sont redirigées vers la ligne d'identifiants,
     * enregistrée avec le site.
     */
    public const CREDENTIAL_ATTRIBUTES = [
        'wp_username',
        'application_password',
        'wp_can_edit',
        'wp_role',
        'connection_status',
        'connection_message',
        'last_checked_at',
    ];

    /** Identifiants chargés pour cette instance. */
    protected ?SiteCredential $loadedCredential = null;

    protected function casts(): array
    {
        return [
            'last_sync_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Les identifiants suivent le site : créés avec lui, et enregistrés à
        // chaque sauvegarde s'ils ont été modifiés.
        static::saved(function (WordpressSite $site) {
            $credential = $site->loadedCredential;

            if ($credential === null && $site->wasRecentlyCreated) {
                $credential = $site->loadedCredential = new SiteCredential;
            }

            if ($credential === null || ($credential->exists && ! $credential->isDirty())) {
                return;
            }

            $credential->wordpress_site_id ??= $site->id;
            $credential->save();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function siteCredential(): HasOne
    {
        return $this->hasOne(SiteCredential::class);
    }

    public function agentAssignments(): HasMany
    {
        return $this->hasMany(SiteAgentAssignment::class);
    }

    /* --- Identifiants ----------------------------------------------------------- */

    /** Identifiants WordPress du site (une ligne créée au besoin). */
    public function credential(): SiteCredential
    {
        if ($this->loadedCredential !== null) {
            return $this->loadedCredential;
        }

        $credential = $this->exists
            ? ($this->relationLoaded('siteCredential') ? $this->siteCredential : $this->siteCredential()->first())
            : null;

        return $this->loadedCredential = $credential ?? new SiteCredential(
            $this->exists ? ['wordpress_site_id' => $this->id] : []
        );
    }

    public function refresh()
    {
        $this->loadedCredential = null;

        return parent::refresh();
    }

    /* --- Accès des agents -------------------------------------------------------- */

    /**
     * Sites accessibles à un compte : tous pour l'Admin, ceux qui lui sont
     * assignés « En cours » pour un Agent. Un site « Terminé » par l'Admin
     * disparaît de l'espace de l'agent — sauf pour ses statistiques
     * ($includeDone), où ses corrections passées restent consultables.
     *
     * @param  Builder<WordpressSite>  $query
     * @return Builder<WordpressSite>
     */
    public function scopeAccessibleBy(Builder $query, User $user, bool $includeDone = false): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereHas('agentAssignments', fn (Builder $q) => $q
            ->where('user_id', $user->id)
            ->when(! $includeDone, fn (Builder $q) => $q->where('status', SiteAgentAssignment::STATUS_IN_PROGRESS)));
    }

    public function isAccessibleBy(User $user): bool
    {
        return $user->isAdmin() || $this->agentAssignments()
            ->where('user_id', $user->id)
            ->where('status', SiteAgentAssignment::STATUS_IN_PROGRESS)
            ->exists();
    }

    public function assignmentOf(User $user): ?SiteAgentAssignment
    {
        return $this->relationLoaded('agentAssignments')
            ? $this->agentAssignments->firstWhere('user_id', $user->id)
            : $this->agentAssignments()->where('user_id', $user->id)->first();
    }

    /* Attributs délégués aux identifiants (voir CREDENTIAL_ATTRIBUTES). */

    public function getWpUsernameAttribute(): mixed { return $this->credential()->wp_username; }
    public function setWpUsernameAttribute(mixed $value): void { $this->credential()->wp_username = $value; }

    public function getApplicationPasswordAttribute(): mixed { return $this->credential()->application_password; }
    public function setApplicationPasswordAttribute(mixed $value): void { $this->credential()->application_password = $value; }

    public function getWpCanEditAttribute(): mixed { return $this->credential()->wp_can_edit; }
    public function setWpCanEditAttribute(mixed $value): void { $this->credential()->wp_can_edit = $value; }

    public function getWpRoleAttribute(): mixed { return $this->credential()->wp_role; }
    public function setWpRoleAttribute(mixed $value): void { $this->credential()->wp_role = $value; }

    public function getConnectionStatusAttribute(): mixed { return $this->credential()->connection_status; }
    public function setConnectionStatusAttribute(mixed $value): void { $this->credential()->connection_status = $value; }

    public function getConnectionMessageAttribute(): mixed { return $this->credential()->connection_message; }
    public function setConnectionMessageAttribute(mixed $value): void { $this->credential()->connection_message = $value; }

    public function getLastCheckedAtAttribute(): mixed { return $this->credential()->last_checked_at; }
    public function setLastCheckedAtAttribute(mixed $value): void { $this->credential()->last_checked_at = $value; }


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
