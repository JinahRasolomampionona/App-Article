<?php

namespace App\Services\WordPress;

use App\Models\WordpressArticle;
use App\Models\WordpressCategory;
use Illuminate\Support\Facades\Log;

/**
 * Écritures vers WordPress pour un article.
 *
 * Seuls les champs réellement modifiés sont transmis : envoyer l'intégralité de
 * l'article à chaque sauvegarde risquerait d'écraser des changements faits
 * ailleurs entre-temps.
 */
class WordPressArticleService
{
    public function __construct(
        protected WordPressApiService $api,
        protected WordPressSyncService $sync,
        protected PostMapper $mapper,
    ) {}

    /**
     * @param  array<string, mixed>  $data  title, content, slug, status,
     *                                      featured_media_id, categories (ids locaux)
     * @return array{article: WordpressArticle, changed: array<int, string>}
     */
    public function update(WordpressArticle $article, array $data): array
    {
        $payload = $this->buildPayload($article, $data);

        if ($payload === []) {
            return ['article' => $article, 'changed' => []];
        }

        $post = $this->api->updatePost($article->site, $article->wp_id, $payload);

        $this->applyRemoteState($article, $post);

        Log::info('Article WordPress mis à jour', [
            'site_id' => $article->wordpress_site_id,
            'wp_id' => $article->wp_id,
            'fields' => array_keys($payload),
        ]);

        return ['article' => $article, 'changed' => array_keys($payload)];
    }

    /**
     * Construit le corps de la requête WordPress à partir des seules
     * différences réelles.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function buildPayload(WordpressArticle $article, array $data): array
    {
        $payload = [];

        if (array_key_exists('title', $data) && (string) $data['title'] !== (string) $article->title) {
            $payload['title'] = (string) $data['title'];
        }

        if (array_key_exists('content', $data) && (string) $data['content'] !== (string) $article->content) {
            $payload['content'] = (string) $data['content'];
        }

        if (array_key_exists('slug', $data)) {
            $slug = trim((string) $data['slug']);

            if ($slug !== '' && $slug !== (string) $article->slug) {
                $payload['slug'] = $slug;
            }
        }

        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];

            if ($status !== '' && $status !== (string) $article->status) {
                $payload['status'] = $status;
            }
        }

        if (array_key_exists('featured_media_id', $data)) {
            $mediaId = (int) $data['featured_media_id'];

            if ($mediaId !== (int) $article->featured_media_id) {
                $payload['featured_media'] = $mediaId;
            }
        }

        if (array_key_exists('categories', $data) && is_array($data['categories'])) {
            $wpIds = $this->toWordPressCategoryIds($article, $data['categories']);
            $current = $article->categories()->pluck('wp_id')->map('intval')->sort()->values()->all();

            $candidate = $wpIds;
            sort($candidate);

            if ($candidate !== $current) {
                $payload['categories'] = $wpIds;
            }
        }

        return $payload;
    }

    /**
     * Convertit des identifiants locaux de catégories en identifiants WordPress,
     * en écartant silencieusement ceux qui n'appartiennent pas au site.
     *
     * @param  array<int, mixed>  $localIds
     * @return array<int, int>
     */
    protected function toWordPressCategoryIds(WordpressArticle $article, array $localIds): array
    {
        $localIds = array_values(array_unique(array_filter(array_map('intval', $localIds))));

        if ($localIds === []) {
            return [];
        }

        return WordpressCategory::query()
            ->where('wordpress_site_id', $article->wordpress_site_id)
            ->whereIn('id', $localIds)
            ->pluck('wp_id')
            ->map('intval')
            ->values()
            ->all();
    }

    /**
     * Réaligne la copie locale sur ce que WordPress vient de confirmer.
     *
     * @param  array<string, mixed>  $post
     */
    protected function applyRemoteState(WordpressArticle $article, array $post): void
    {
        $attributes = $this->mapper->toAttributes($post);
        $attributes['synced_at'] = now();

        if ($attributes['featured_media_id'] > 0) {
            $media = $this->api->fetchMedia($article->site, $attributes['featured_media_id']);
            $attributes['featured_media_url'] = isset($media['source_url']) ? (string) $media['source_url'] : null;
            $attributes['featured_media_alt'] = isset($media['alt_text']) ? (string) $media['alt_text'] : null;
        } else {
            $attributes['featured_media_url'] = null;
            $attributes['featured_media_alt'] = null;
        }

        $article->fill($attributes)->save();

        $categoryMap = $article->site->categories()->pluck('id', 'wp_id')->all();
        $localIds = [];

        foreach ($this->mapper->categoryIds($post) as $wpId) {
            if (isset($categoryMap[$wpId])) {
                $localIds[] = $categoryMap[$wpId];
            }
        }

        $article->categories()->sync($localIds);
        $article->load('categories');
    }
}
