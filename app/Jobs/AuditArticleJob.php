<?php

namespace App\Jobs;

use App\Models\WordpressArticle;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Audit complet d'un article, règles réseau comprises.
 *
 * Le téléchargement des images est mis en cache par `ImageQualityAnalyzer` :
 * relancer ce job sur un article inchangé ne provoque aucun trafic.
 */
class AuditArticleJob implements ShouldQueue
{
    use Queueable;

    /** Article supprimé entre-temps : job abandonné, pas mis en échec. */
    public bool $deleteWhenMissingModels = true;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(
        public WordpressArticle $article,
        public string $trigger = 'queue',
    ) {}

    public function handle(AuditService $audit): void
    {
        $article = $this->article->loadMissing('site.user');

        $audit->run(
            $article,
            AuditSettings::forUser($article->site?->user),
            allowNetwork: true,
            trigger: $this->trigger,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Audit d’article en échec', [
            'article_id' => $this->article->id,
            'detail' => $exception->getMessage(),
        ]);
    }

    /**
     * Évite d'empiler plusieurs audits du même article dans la file.
     */
    public function uniqueId(): string
    {
        return 'audit-article-'.$this->article->id;
    }
}
