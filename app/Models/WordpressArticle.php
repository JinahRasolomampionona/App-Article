<?php

namespace App\Models;

use App\Services\Stats\StatisticsRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Statuts que l'utilisateur peut poser lui-même depuis le tableau.
     *
     * « OK » n'en fait pas partie : il signifie « aucun problème détecté », ce
     * qui relève du moteur d'audit et non d'une déclaration.
     *
     * @return array<string, string>
     */
    public static function manualStatuses(): array
    {
        return [
            self::AUDIT_NEEDS_FIX => 'À corriger',
            self::AUDIT_FIXED => 'Corrigé',
        ];
    }

    /**
     * Le statut courant peut-il être modifié à la main ?
     *
     * Un article sans aucun problème détecté reste en « OK » : le proposer
     * comme « Corrigé » n'aurait pas de sens, et le basculer en « À corriger »
     * inventerait un défaut que l'audit n'a pas vu.
     */
    public function statusIsEditable(): bool
    {
        return in_array($this->audit_status, [self::AUDIT_NEEDS_FIX, self::AUDIT_FIXED], true);
    }

    /**
     * Applique un statut choisi par l'utilisateur.
     *
     * « Corrigé » clôture les remarques ouvertes — sinon le tableau afficherait
     * un statut corrigé à côté de problèmes toujours listés — en les marquant
     * comme résolues à la main. Revenir à « À corriger » les rouvre : seules
     * celles que l'utilisateur avait lui-même clôturées sont concernées, une
     * remarque réellement résolue par un audit n'est jamais ressuscitée.
     *
     * Le prochain audit reste souverain : si le défaut est toujours là, il
     * rouvrira la remarque et le statut repassera à « À corriger ».
     */
    public function applyManualStatus(string $status): void
    {
        if (! array_key_exists($status, self::manualStatuses())) {
            return;
        }

        $previousStatus = $this->audit_status;

        if ($status === self::AUDIT_FIXED) {
            $resolved = $this->openIssues()->count();

            $this->openIssues()->update([
                'resolved_at' => now(),
                'resolved_manually' => true,
            ]);

            $this->forceFill([
                'audit_status' => self::AUDIT_FIXED,
                'issues_count' => 0,
                'issues_resolved_at' => now(),
                'status_set_manually_at' => now(),
            ])->save();

            // L'article est déclaré corrigé : il entre dans l'historique des
            // statistiques, en restant distingué d'une correction confirmée
            // par un audit.
            app(StatisticsRecorder::class)->record(
                $this,
                $previousStatus,
                self::AUDIT_FIXED,
                manual: true,
                issuesResolved: $resolved,
            );

            return;
        }

        $this->issues()
            ->whereNotNull('resolved_at')
            ->where('resolved_manually', true)
            ->update(['resolved_at' => null, 'resolved_manually' => false]);

        $this->forceFill([
            'audit_status' => self::AUDIT_NEEDS_FIX,
            'issues_count' => $this->openIssues()->count(),
            'status_set_manually_at' => now(),
        ])->save();
    }

    public function statusLabel(): ?string
    {
        return match ($this->audit_status) {
            self::AUDIT_NEEDS_FIX => 'À corriger',
            self::AUDIT_FIXED => 'Corrigé',
            self::AUDIT_OK => 'OK',
            default => null,
        };
    }

    public function statusVariant(): string
    {
        return match ($this->audit_status) {
            self::AUDIT_NEEDS_FIX => 'danger',
            self::AUDIT_FIXED => 'success',
            self::AUDIT_OK => 'neutral',
            default => 'muted',
        };
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
