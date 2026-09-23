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
        'agent',
        'agent_assigned_at',
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
            'agent_assigned_at' => 'datetime',
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
     * Filtre par agent. La valeur spéciale `none` isole les articles encore
     * non assignés, qui sont précisément ceux à répartir.
     *
     * @param  Builder<WordpressArticle>  $query
     * @return Builder<WordpressArticle>
     */
    public function scopeForAgent(Builder $query, ?string $agent): Builder
    {
        if ($agent === null || $agent === '') {
            return $query;
        }

        if ($agent === 'none') {
            return $query->whereNull('agent');
        }

        return $query->where('agent', $agent);
    }
}
