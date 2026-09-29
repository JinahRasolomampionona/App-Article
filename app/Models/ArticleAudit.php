<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'wordpress_article_id',
        'status',
        'issues_count',
        'resolved_count',
        'content_hash',
        'trigger_source',
        'duration_ms',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'issues_count' => 'integer',
            'resolved_count' => 'integer',
            'duration_ms' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(WordpressArticle::class, 'wordpress_article_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ArticleAuditIssue::class);
    }

    /** Origine du scan, lisible dans l'historique. */
    public function triggerLabel(): string
    {
        return match ($this->trigger_source) {
            'save' => 'Après mise à jour',
            'manual' => 'Audit manuel',
            'bulk' => 'Audit en masse',
            'sync' => 'Synchronisation',
            'finish' => 'Fin de correction',
            'command' => 'Commande serveur',
            default => (string) $this->trigger_source,
        };
    }

    /** Résultat du scan : problèmes trouvés ou non. */
    public function resultLabel(): string
    {
        return $this->issues_count > 0
            ? $this->issues_count.' problème(s)'
            : 'Aucun problème';
    }

    public function resultVariant(): string
    {
        return $this->issues_count > 0 ? 'danger' : 'success';
    }
}
