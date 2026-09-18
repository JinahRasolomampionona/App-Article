<?php

namespace App\Jobs;

use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Programme l'audit de tous les articles d'un site.
 *
 * Le travail est éclaté en un job par article : la file reste réactive et un
 * article en erreur n'interrompt pas l'audit des autres.
 */
class AuditSiteJob implements ShouldQueue
{
    use Queueable;

    /** Site supprimé entre-temps : job abandonné, pas mis en échec. */
    public bool $deleteWhenMissingModels = true;

    public int $timeout = 300;

    public function __construct(
        public WordpressSite $site,
        public bool $onlyStale = true,
    ) {}

    public function handle(): void
    {
        $this->site->articles()
            ->select(['id', 'wordpress_site_id', 'title', 'content', 'featured_media_id', 'featured_media_url', 'audited_content_hash'])
            ->chunkById(200, function ($articles) {
                foreach ($articles as $article) {
                    /** @var WordpressArticle $article */
                    if ($this->onlyStale && ! $article->auditIsStale()) {
                        continue;
                    }

                    // Le job sérialise l'identifiant : le modèle complet sera
                    // rechargé au moment de l'exécution.
                    AuditArticleJob::dispatch($article, 'sync');
                }
            });
    }
}
