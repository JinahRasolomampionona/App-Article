<?php

namespace App\Services\WordPress;

use App\Models\WordpressArticle;
use App\Models\WordpressCategory;
use App\Models\WordpressSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Récupère le contenu distant et le réplique en base locale.
 *
 * La copie locale est un cache de travail : WordPress reste la source de
 * vérité. Elle existe pour permettre le filtrage, la recherche, la pagination
 * et l'audit sans marteler l'API du site.
 */
class WordPressSyncService
{
    public function __construct(
        protected WordPressApiService $api,
        protected PostMapper $mapper,
    ) {}

    /**
     * Synchronisation complète : catégories puis articles.
     *
     * @return array{categories: int, articles: int, removed: int}
     */
    public function syncSite(WordpressSite $site): array
    {
        $categories = $this->syncCategories($site);
        $articles = $this->syncArticles($site);

        $site->forceFill([
            'last_sync_at' => now(),
            'sync_status' => 'idle',
            'sync_message' => null,
            'connection_status' => WordpressSite::STATUS_CONNECTED,
            'last_checked_at' => now(),
        ])->save();

        Log::info('Synchronisation WordPress terminée', [
            'site_id' => $site->id,
            'categories' => $categories,
            'articles' => $articles['synced'],
            'removed' => $articles['removed'],
        ]);

        return [
            'categories' => $categories,
            'articles' => $articles['synced'],
            'removed' => $articles['removed'],
        ];
    }

    /**
     * @return int Nombre de catégories synchronisées
     */
    public function syncCategories(WordpressSite $site): int
    {
        $remote = $this->api->fetchAllCategories($site);
        $seen = [];

        foreach ($remote as $category) {
            if (! isset($category['id'])) {
                continue;
            }

            $wpId = (int) $category['id'];
            $seen[] = $wpId;

            WordpressCategory::updateOrCreate(
                ['wordpress_site_id' => $site->id, 'wp_id' => $wpId],
                [
                    'name' => html_entity_decode((string) ($category['name'] ?? 'Sans nom'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'slug' => isset($category['slug']) ? (string) $category['slug'] : null,
                    'parent_wp_id' => (int) ($category['parent'] ?? 0),
                    'posts_count' => (int) ($category['count'] ?? 0),
                ]
            );
        }

        if ($seen !== []) {
            $site->categories()->whereNotIn('wp_id', $seen)->delete();
        }

        return count($seen);
    }

    /**
     * @return array{synced: int, removed: int}
     */
    public function syncArticles(WordpressSite $site): array
    {
        $categoryMap = $site->categories()->pluck('id', 'wp_id')->all();
        $seenWpIds = [];
        $max = (int) config('articleguard.sync.max_articles', 5000);

        $this->api->eachPost($site, function (array $posts) use ($site, $categoryMap, &$seenWpIds) {
            $mediaIds = array_values(array_filter(array_map(
                fn (array $post) => (int) ($post['featured_media'] ?? 0),
                $posts
            )));

            // Une seule requête média pour toute la page plutôt qu'une par article.
            $media = $mediaIds !== [] ? $this->api->fetchMediaByIds($site, $mediaIds) : [];

            DB::transaction(function () use ($site, $posts, $categoryMap, $media, &$seenWpIds) {
                foreach ($posts as $post) {
                    $attributes = $this->mapper->toAttributes($post);

                    if ($attributes['wp_id'] <= 0) {
                        continue;
                    }

                    $seenWpIds[] = $attributes['wp_id'];

                    $attributes += $this->mapper->mediaAttributes(
                        $media[$attributes['featured_media_id']] ?? null
                    );
                    $attributes['synced_at'] = now();

                    $article = WordpressArticle::firstOrNew([
                        'wordpress_site_id' => $site->id,
                        'wp_id' => $attributes['wp_id'],
                    ]);

                    $article->fill($attributes);

                    // Un contenu modifié côté WordPress rend l'audit précédent caduc.
                    if ($article->exists && $article->auditIsStale()) {
                        $article->audit_status = $article->issues_count > 0
                            ? WordpressArticle::AUDIT_NEEDS_FIX
                            : WordpressArticle::AUDIT_PENDING;
                    }

                    $article->save();

                    $this->syncArticleCategories($article, $this->mapper->categoryIds($post), $categoryMap);
                }
            });
        }, $max);

        $removed = 0;

        if ($seenWpIds !== []) {
            // Les articles absents de WordPress sont retirés de la copie locale
            // pour éviter d'auditer du contenu qui n'existe plus.
            $removed = $site->articles()->whereNotIn('wp_id', $seenWpIds)->delete();
        }

        return ['synced' => count(array_unique($seenWpIds)), 'removed' => $removed];
    }

    /**
     * Rafraîchit un article unique depuis WordPress.
     */
    public function syncArticle(WordpressArticle $article): WordpressArticle
    {
        $site = $article->site;
        $post = $this->api->fetchPost($site, $article->wp_id);

        $attributes = $this->mapper->toAttributes($post);
        $attributes['synced_at'] = now();

        $attributes += $this->mapper->mediaAttributes(
            $attributes['featured_media_id'] > 0
                ? $this->api->fetchMedia($site, $attributes['featured_media_id'])
                : null
        );

        $article->fill($attributes)->save();

        $categoryMap = $site->categories()->pluck('id', 'wp_id')->all();
        $this->syncArticleCategories($article, $this->mapper->categoryIds($post), $categoryMap);

        return $article->refresh();
    }

    /**
     * @param  array<int, int>  $wpCategoryIds
     * @param  array<int, int>  $categoryMap  wp_id => id local
     */
    protected function syncArticleCategories(WordpressArticle $article, array $wpCategoryIds, array $categoryMap): void
    {
        $localIds = [];

        foreach ($wpCategoryIds as $wpCategoryId) {
            if (isset($categoryMap[$wpCategoryId])) {
                $localIds[] = $categoryMap[$wpCategoryId];
            }
        }

        $article->categories()->sync($localIds);
    }
}
