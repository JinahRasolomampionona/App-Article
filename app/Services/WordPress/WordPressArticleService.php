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

        $confirmedAfterTimeout = false;

        try {
            $post = $this->api->updatePost($article->site, $article->wp_id, $payload);
        } catch (WordPressApiException $e) {
            if ($e->reason !== 'unreachable') {
                throw $e;
            }

            $post = $this->confirmAfterTimeout($article, $payload, $e);
            $confirmedAfterTimeout = true;
        }

        $this->applyRemoteState($article, $post);

        Log::info('Article WordPress mis à jour', [
            'site_id' => $article->wordpress_site_id,
            'wp_id' => $article->wp_id,
            'fields' => array_keys($payload),
            'confirmed_after_timeout' => $confirmedAfterTimeout,
        ]);

        return ['article' => $article, 'changed' => array_keys($payload)];
    }

    /**
     * La réponse de WordPress n'est pas arrivée à temps. Sur un site lent,
     * l'enregistrement est pourtant souvent terminé : on relit l'article pour
     * le savoir plutôt que d'annoncer un échec — ou de renvoyer l'écriture.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function confirmAfterTimeout(WordpressArticle $article, array $payload, WordPressApiException $timeout): array
    {
        try {
            $post = $this->api->fetchPostForEdit($article->site, $article->wp_id);
        } catch (WordPressApiException $e) {
            $post = null;
        }

        if ($post !== null && $this->wasApplied($article, $post, $payload)) {
            return $post;
        }

        Log::warning('Mise à jour WordPress non confirmée', [
            'site_id' => $article->wordpress_site_id,
            'wp_id' => $article->wp_id,
            'detail' => $timeout->context['detail'] ?? null,
        ]);

        throw new WordPressApiException(
            'WordPress n’a pas répondu à temps et la mise à jour n’a pas pu être confirmée. '
            .'Vos modifications restent dans l’éditeur : réessayez dans quelques instants.',
            'unreachable',
            null,
            $timeout->context,
            $timeout,
        );
    }

    /**
     * L'article distant porte-t-il les modifications envoyées ?
     *
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $payload
     */
    protected function wasApplied(WordpressArticle $article, array $post, array $payload): bool
    {
        $remote = $this->mapper->toAttributes($post);

        // Toute sauvegarde fait avancer `modified` : c'est la preuve la plus sûre,
        // WordPress pouvant normaliser le HTML reçu (kses, sauts de ligne…).
        if ($remote['wordpress_modified_at'] !== null
            && ($article->wordpress_modified_at === null || $remote['wordpress_modified_at']->gt($article->wordpress_modified_at))) {
            return true;
        }

        $normalize = fn ($value) => trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);

        foreach ($payload as $field => $value) {
            $matches = match ($field) {
                'title' => $normalize($remote['title']) === $normalize(strip_tags((string) $value)),
                'content' => $normalize($remote['content']) === $normalize($value),
                'slug' => (string) $remote['slug'] === (string) $value,
                'status' => $remote['status'] === (string) $value,
                'featured_media' => $remote['featured_media_id'] === (int) $value,
                'categories' => collect($this->mapper->categoryIds($post))->sort()->values()->all()
                    === collect($value)->map('intval')->sort()->values()->all(),
                default => true,
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
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

        $mediaId = $attributes['featured_media_id'];

        if ($mediaId <= 0) {
            $attributes += $this->mapper->mediaAttributes(null);
        } elseif ($mediaId !== (int) $article->featured_media_id || ! $article->featured_media_url) {
            // Un aller-retour de plus seulement si l'image a changé. L'article
            // est déjà enregistré dans WordPress : un échec ici ne doit pas
            // le faire passer pour une mise à jour ratée.
            try {
                $attributes += $this->mapper->mediaAttributes($this->api->fetchMedia($article->site, $mediaId));
            } catch (WordPressApiException $e) {
                Log::warning('Image mise en avant non récupérée après mise à jour', [
                    'site_id' => $article->wordpress_site_id,
                    'wp_id' => $article->wp_id,
                    'media_id' => $mediaId,
                ]);
            }
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
