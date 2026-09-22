<?php

namespace App\Services\WordPress;

use Carbon\CarbonImmutable;

/**
 * Traduit la représentation d'un article renvoyée par l'API REST WordPress en
 * attributs du modèle local.
 *
 * WordPress renvoie `raw` (HTML réellement stocké) uniquement avec
 * `context=edit` ; sinon seul `rendered` est disponible. L'éditeur doit
 * travailler sur `raw` : c'est ce qui est réellement renvoyé lors de la mise à
 * jour.
 */
class PostMapper
{
    /**
     * Longueurs des colonnes correspondantes de `wordpress_articles`.
     *
     * WordPress n'impose aucune limite à ces champs : un `alt` recopié depuis
     * un paragraphe entier ou un titre à rallonge suffit à faire échouer
     * l'insertion — et donc toute la synchronisation du site. La copie locale
     * n'est qu'un cache de travail : tronquer y est préférable à perdre
     * l'article.
     */
    public const MAX_TITLE = 512;

    public const MAX_SLUG = 191;

    public const MAX_LINK = 1024;

    public const MAX_MEDIA_URL = 1024;

    public const MAX_MEDIA_ALT = 512;

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    public function toAttributes(array $post): array
    {
        return [
            'wp_id' => (int) ($post['id'] ?? 0),
            'title' => (string) $this->truncate($this->plainText($this->field($post, 'title')), self::MAX_TITLE),
            'slug' => $this->truncate(isset($post['slug']) ? (string) $post['slug'] : null, self::MAX_SLUG),
            'link' => $this->truncate(isset($post['link']) ? (string) $post['link'] : null, self::MAX_LINK),
            'content' => $this->field($post, 'content'),
            'excerpt' => $this->excerpt($post),
            'featured_media_id' => (int) ($post['featured_media'] ?? 0),
            'status' => (string) ($post['status'] ?? 'publish'),
            'author_wp_id' => isset($post['author']) ? (int) $post['author'] : null,
            'wordpress_published_at' => $this->date($post, 'date_gmt', 'date'),
            'wordpress_modified_at' => $this->date($post, 'modified_gmt', 'modified'),
        ];
    }

    /**
     * Identifiants WordPress des catégories de l'article.
     *
     * @param  array<string, mixed>  $post
     * @return array<int, int>
     */
    public function categoryIds(array $post): array
    {
        $categories = $post['categories'] ?? [];

        if (! is_array($categories)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $categories)));
    }

    /**
     * Récupère un champ WordPress qui peut être une chaîne ou un objet
     * `{raw, rendered}`.
     *
     * @param  array<string, mixed>  $post
     */
    public function field(array $post, string $key): string
    {
        $value = $post[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            if (isset($value['raw']) && is_string($value['raw'])) {
                return $value['raw'];
            }

            if (isset($value['rendered']) && is_string($value['rendered'])) {
                return $value['rendered'];
            }
        }

        return '';
    }

    /**
     * Attributs locaux décrivant l'image mise en avant.
     *
     * @param  array<string, mixed>|null  $media
     * @return array{featured_media_url: ?string, featured_media_alt: ?string}
     */
    public function mediaAttributes(?array $media): array
    {
        return [
            'featured_media_url' => $this->truncate(
                isset($media['source_url']) ? (string) $media['source_url'] : null,
                self::MAX_MEDIA_URL,
            ),
            'featured_media_alt' => $this->truncate(
                isset($media['alt_text']) ? $this->plainText((string) $media['alt_text']) : null,
                self::MAX_MEDIA_ALT,
            ),
        ];
    }

    /**
     * Tronque sans couper au milieu d'un caractère multi-octets.
     */
    public function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    /**
     * Extrait saisi dans WordPress, ou à défaut les premiers mots du contenu —
     * comme le fait WordPress (55 mots), sans lui demander de le calculer :
     * c'est l'un des champs les plus coûteux de l'API.
     *
     * @param  array<string, mixed>  $post
     */
    protected function excerpt(array $post): string
    {
        $excerpt = $this->plainText($this->field($post, 'excerpt'));

        if ($excerpt !== '') {
            return $excerpt;
        }

        // Shortcodes retirés : leur syntaxe n'a rien à faire dans un extrait.
        $content = preg_replace('/\[\/?[a-zA-Z][^\]]*\]/', ' ', $this->field($post, 'content')) ?? '';
        $content = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $content) ?? $content;
        $words = preg_split('/\s+/u', $this->plainText(str_replace('<', ' <', $content)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) > 55
            ? implode(' ', array_slice($words, 0, 55)).'…'
            : implode(' ', $words);
    }

    protected function plainText(string $value): string
    {
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    protected function date(array $post, string $gmtKey, string $localKey): ?CarbonImmutable
    {
        $value = $post[$gmtKey] ?? null;
        $timezone = 'UTC';

        if (! is_string($value) || $value === '') {
            $value = $post[$localKey] ?? null;
            // `date`/`modified` sont exprimés dans le fuseau du site : à défaut de
            // le connaître, on les interprète comme UTC plutôt que de perdre
            // l'information.
            $timezone = 'UTC';
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
