<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée d'historique : un article est passé à « OK » ou « Corrigé ».
 *
 * Le nom du site et le titre de l'article sont recopiés à l'enregistrement.
 * Supprimer un site vide ses articles, mais l'historique reste lisible : c'est
 * tout l'intérêt de cette table.
 */
class ArticleStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'article_status_history';

    protected $fillable = [
        'user_id',
        'wordpress_site_id',
        'site_name',
        'site_url',
        'wordpress_article_id',
        'wp_id',
        'article_title',
        'article_url',
        'status',
        'resolved_manually',
        'agent',
        'agent_user_id',
        'issues_resolved',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'wp_id' => 'integer',
            'issues_resolved' => 'integer',
            'resolved_manually' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WordpressSite::class, 'wordpress_site_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(WordpressArticle::class, 'wordpress_article_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Compte de l'agent crédité de la correction. */
    public function agentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    /** Le site d'origine n'existe plus : la ligne ne vaut plus que comme archive. */
    public function siteWasDeleted(): bool
    {
        return $this->wordpress_site_id === null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            WordpressArticle::AUDIT_FIXED => 'Corrigé',
            default => 'OK',
        };
    }

    public function statusVariant(): string
    {
        return $this->status === WordpressArticle::AUDIT_FIXED ? 'success' : 'muted';
    }

    /**
     * @param  Builder<ArticleStatusHistory>  $query
     * @return Builder<ArticleStatusHistory>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
