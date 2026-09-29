<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class WordpressArticle extends Model
{
    use HasFactory;

    /** Aucun audit n'a encore été exécuté sur la version courante du contenu. */
    public const AUDIT_PENDING = 'pending';

    /** Audit exécuté, aucun problème détecté et aucun historique de correction. */
    public const AUDIT_OK = 'ok';

    /** Au moins un problème ouvert. */
    public const AUDIT_NEEDS_FIX = 'needs_fix';

    /** Des problèmes existaient puis ont été confirmés résolus par un nouvel audit. */
    public const AUDIT_FIXED = 'fixed';

    /** Statut affiché : aucun problème détecté, l'article reste à vérifier. */
    public const STATUS_TO_REVIEW = 'to_review';

    /** Statut affiché : l'agent a déclaré l'article corrigé (manuel). */
    public const STATUS_DONE = 'fixed';

    /** Filtres de statut acceptés par le tableau des articles. */
    public const STATUS_FILTERS = [self::AUDIT_NEEDS_FIX, self::STATUS_TO_REVIEW, self::STATUS_DONE, self::AUDIT_PENDING];

    /** Relations affichées par une ligne du tableau (chargement anticipé). */
    public const ROW_RELATIONS = [
        'categories:id,name',
        'openIssues:id,wordpress_article_id,rule_type,severity,message',
        'assignee:id,name',
        'completer:id,name',
        'notes.author:id,name',
    ];

    protected $fillable = [
        'wordpress_site_id',
        'wp_id',
        'title',
        'slug',
        'link',
        'content',
        'excerpt',
        'featured_media_id',
        'featured_media_url',
        'featured_media_alt',
        'status',
        'author_wp_id',
        'wordpress_published_at',
        'wordpress_modified_at',
        'synced_at',
        'audit_status',
        // Le verrou (`assigned_to`, `locked_at`, `lock_expires_at`) n'est pas
        // assignable en masse : il ne se pose que par ArticleLockService.
        'issues_count',
        'last_audited_at',
        'issues_resolved_at',
        'audited_content_hash',
        'status_set_manually_at',
    ];

    protected function casts(): array
    {
        return [
            'wp_id' => 'integer',
            'featured_media_id' => 'integer',
            'issues_count' => 'integer',
            'wordpress_published_at' => 'datetime',
            'wordpress_modified_at' => 'datetime',
            'synced_at' => 'datetime',
            'last_audited_at' => 'datetime',
            'assigned_to' => 'integer',
            'locked_at' => 'datetime',
            'lock_expires_at' => 'datetime',
            'issues_resolved_at' => 'datetime',
            'status_set_manually_at' => 'datetime',
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WordpressSite::class, 'wordpress_site_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            WordpressCategory::class,
            'article_category',
            'wordpress_article_id',
            'wordpress_category_id'
        );
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ArticleAudit::class)->latest();
    }

    /**
     * Tous les scans, sans ordre imposé : sert les agrégats (premier et
     * dernier scan, nombre de scans) de l'historique des audits.
     */
    public function scans(): HasMany
    {
        return $this->hasMany(ArticleAudit::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ArticleAuditIssue::class);
    }

    public function openIssues(): HasMany
    {
        return $this->hasMany(ArticleAuditIssue::class)->whereNull('resolved_at');
    }

    /** Compte qui détient (ou a détenu, si le verrou a expiré) l'article. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ArticleAssignment::class);
    }

    /** Agent qui a déclaré l'article « Corrigé ». */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** Commentaires de l'Admin, du plus récent au plus ancien. */
    public function notes(): HasMany
    {
        return $this->hasMany(ArticleNote::class)->latest()->latest('id');
    }

    /* --- Fin de traitement ---------------------------------------------------- */

    /**
     * L'agent a déclaré l'article « Corrigé » : il quitte la liste des agents
     * et attend la vérification de l'Admin.
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Statut affiché, qui combine l'audit et la fin de traitement :
     *
     * - `fixed` (Corrigé) : déclaré terminé par l'agent ;
     * - `needs_fix` (À corriger) : l'audit a trouvé au moins un problème ;
     * - `to_review` (À vérifier) : aucun problème détecté, reste à vérifier ;
     * - `pending` : pas encore audité.
     */
    public function displayStatus(): string
    {
        return match (true) {
            $this->isCompleted() => self::STATUS_DONE,
            $this->audit_status === self::AUDIT_NEEDS_FIX => self::AUDIT_NEEDS_FIX,
            in_array($this->audit_status, [self::AUDIT_OK, self::AUDIT_FIXED], true) => self::STATUS_TO_REVIEW,
            default => self::AUDIT_PENDING,
        };
    }

    /**
     * Commentaires de l'Admin qui concernent la prise en charge en cours :
     * ceux écrits depuis que l'agent actuel a l'article.
     *
     * @return Collection<int, ArticleNote>
     */
    public function currentNotes(): Collection
    {
        if (! $this->isLocked() || $this->locked_at === null) {
            return collect();
        }

        $since = $this->locked_at->copy()->subMinute();
        $notes = $this->relationLoaded('notes') ? $this->notes : $this->notes()->with('author:id,name')->get();

        return $notes->filter(fn (ArticleNote $note) => $note->created_at !== null && $note->created_at->gte($since))->values();
    }

    /* --- Verrou de traitement ------------------------------------------------ */

    /**
     * Un agent travaille-t-il actuellement sur l'article ?
     *
     * Un verrou expiré ne compte plus, même si la colonne n'a pas encore été
     * nettoyée : la disponibilité ne dépend jamais du passage d'une tâche
     * planifiée.
     */
    public function isLocked(): bool
    {
        return $this->assigned_to !== null
            && $this->lock_expires_at !== null
            && $this->lock_expires_at->isFuture();
    }

    public function isLockedBy(?User $user): bool
    {
        return $user !== null && $this->isLocked() && $this->assigned_to === $user->id;
    }

    public function isLockedByOther(?User $user): bool
    {
        return $this->isLocked() && ($user === null || $this->assigned_to !== $user->id);
    }

    /** Identifiant de l'agent actif, `null` si l'article est disponible. */
    public function activeAgentId(): ?int
    {
        return $this->isLocked() ? $this->assigned_to : null;
    }

    /** Nom de l'agent actif, `null` si l'article est disponible. */
    public function activeAgentName(): ?string
    {
        if (! $this->isLocked()) {
            return null;
        }

        return $this->assignee?->name ?? 'un autre agent';
    }

    /**
     * État de traitement vu par un utilisateur : `available`, `mine`, `other`.
     * Distinct du statut d'audit — un article peut être « À corriger » et
     * « En cours par Daniella » à la fois.
     */
    public function lockStateFor(?User $user): string
    {
        if (! $this->isLocked()) {
            return 'available';
        }

        return $this->isLockedBy($user) ? 'mine' : 'other';
    }

    /**
     * Articles actuellement pris en charge.
     *
     * @param  Builder<WordpressArticle>  $query
     * @return Builder<WordpressArticle>
     */
    public function scopeLocked(Builder $query): Builder
    {
        return $query->whereNotNull('assigned_to')->where('lock_expires_at', '>', now());
    }

    /**
     * Articles disponibles : jamais pris, libérés, ou dont le verrou a expiré.
     *
     * @param  Builder<WordpressArticle>  $query
     * @return Builder<WordpressArticle>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('assigned_to')
                ->orWhereNull('lock_expires_at')
                ->orWhere('lock_expires_at', '<=', now());
        });
    }

    /**
     * Empreinte de la version auditée. Deux articles dont l'empreinte est
     * identique produisent le même audit : inutile de le relancer.
     */
    public function contentHash(): string
    {
        return hash('sha256', implode('|', [
            (string) $this->title,
            (string) $this->content,
            (string) $this->featured_media_id,
            (string) $this->featured_media_url,
        ]));
    }

    public function auditIsStale(): bool
    {
        return $this->audited_content_hash !== $this->contentHash();
    }

    /**
     * Choix du sélecteur de statut : le statut actuel (« À corriger » ou
     * « À vérifier ») et « Corrigé », que l'agent pose à la main une fois
     * l'article traité.
     *
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return [
            $this->displayStatus() => (string) $this->statusLabel(),
            self::STATUS_DONE => 'Corrigé',
        ];
    }

    /**
     * Le sélecteur n'est proposé que sur un article audité et pas encore
     * déclaré corrigé : un article corrigé ne revient à un agent que par une
     * réassignation de l'Admin.
     */
    public function statusIsEditable(): bool
    {
        return in_array($this->displayStatus(), [self::AUDIT_NEEDS_FIX, self::STATUS_TO_REVIEW], true);
    }

    public function statusLabel(): ?string
    {
        return match ($this->displayStatus()) {
            self::STATUS_DONE => 'Corrigé',
            self::AUDIT_NEEDS_FIX => 'À corriger',
            self::STATUS_TO_REVIEW => 'À vérifier',
            default => null,
        };
    }

    public function statusVariant(): string
    {
        return match ($this->displayStatus()) {
            self::STATUS_DONE => 'success',
            self::AUDIT_NEEDS_FIX => 'danger',
            self::STATUS_TO_REVIEW => 'info',
            default => 'muted',
        };
    }

    /**
     * Filtre sur le statut affiché (voir displayStatus()).
     *
     * @param  Builder<WordpressArticle>  $query
     * @return Builder<WordpressArticle>
     */
    public function scopeWithDisplayStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            self::STATUS_DONE => $query->whereNotNull('completed_at'),
            self::AUDIT_NEEDS_FIX => $query->whereNull('completed_at')->where('audit_status', self::AUDIT_NEEDS_FIX),
            self::STATUS_TO_REVIEW => $query->whereNull('completed_at')->whereIn('audit_status', [self::AUDIT_OK, self::AUDIT_FIXED]),
            self::AUDIT_PENDING => $query->whereNull('completed_at')->where('audit_status', self::AUDIT_PENDING),
            default => $query,
        };
    }

    /**
     * Articles de la liste d'un utilisateur : tous pour l'Admin ; pour un
     * Agent, ceux qui ne sont pas encore déclarés corrigés — sauf s'il filtre
     * sur « Corrigés », auquel cas il ne voit que les siens.
     *
     * @param  Builder<WordpressArticle>  $query
     * @return Builder<WordpressArticle>
     */
    public function scopeVisibleInListTo(Builder $query, User $user, ?string $status = null): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $status === self::STATUS_DONE
            ? $query->where('completed_by', $user->id)
            : $query->whereNull('completed_at');
    }

    /**
     * Chemin relatif de l'article, utilisé dans la colonne « URL » du tableau.
     */
    public function relativePath(): string
    {
        if (blank($this->link)) {
            return $this->slug ? '/'.ltrim($this->slug, '/') : '';
        }

        $path = parse_url($this->link, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/'.ltrim((string) $this->slug, '/');
    }

    /**
     * Recherche globale : titre, slug, URL et identifiant WordPress.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

            $q->where('title', 'like', $like)
                ->orWhere('slug', 'like', $like)
                ->orWhere('link', 'like', $like);

            if (ctype_digit($term)) {
                $q->orWhere('wp_id', (int) $term);
            }
        });
    }

    /**
     * Filtre multi-catégories.
     *
     * @param  array<int, int>  $categoryIds  identifiants locaux des catégories
     * @param  string  $mode  `any` (OR, défaut) ou `all` (AND)
     */
    public function scopeInCategories(Builder $query, array $categoryIds, string $mode = 'any'): Builder
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));

        if ($categoryIds === []) {
            return $query;
        }

        if ($mode === 'all') {
            foreach ($categoryIds as $categoryId) {
                $query->whereHas('categories', fn (Builder $q) => $q->where('wordpress_categories.id', $categoryId));
            }

            return $query;
        }

        return $query->whereHas(
            'categories',
            fn (Builder $q) => $q->whereIn('wordpress_categories.id', $categoryIds)
        );
    }

    /**
     * Filtre par agent actif. `none` isole les articles disponibles — ceux à
     * répartir —, un identifiant de compte ceux qu'il traite en ce moment.
     *
     * @param  Builder<WordpressArticle>  $query
     * @return Builder<WordpressArticle>
     */
    public function scopeForAgent(Builder $query, int|string|null $agent): Builder
    {
        if ($agent === null || $agent === '') {
            return $query;
        }

        if ($agent === 'none') {
            return $query->available();
        }

        return $query->locked()->where('assigned_to', (int) $agent);
    }
}
