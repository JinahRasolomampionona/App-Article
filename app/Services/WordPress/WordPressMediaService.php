<?php

namespace App\Services\WordPress;

use App\Models\WordpressSite;
use App\Support\UnsafeUrlException;
use App\Support\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Accès à la médiathèque WordPress depuis l'éditeur d'article.
 */
class WordPressMediaService
{
    public function __construct(
        protected WordPressApiService $api,
        protected UrlGuard $guard,
    ) {}

    /**
     * Parcourt la médiathèque, avec recherche optionnelle.
     *
     * @return array{items: array<int, array<string, mixed>>, page: int, total_pages: int, total: int}
     */
    public function library(WordpressSite $site, ?string $search = null, int $page = 1, int $perPage = 24): array
    {
        $result = $this->api->searchMedia($site, $search, $page, $perPage);

        return [
            'items' => array_map(fn (array $item) => $this->present($item), $result->items),
            'page' => $result->page,
            'total_pages' => $result->totalPages,
            'total' => $result->total,
        ];
    }

    /**
     * Téléverse un fichier vers la médiathèque WordPress.
     *
     * @return array<string, mixed>
     */
    public function upload(WordpressSite $site, UploadedFile $file): array
    {
        $filename = $this->safeFilename($file);

        $media = $this->api->uploadMedia(
            $site,
            (string) file_get_contents($file->getRealPath()),
            $filename,
            $file->getMimeType() ?: 'application/octet-stream',
        );

        return $this->present($media);
    }

    /**
     * « Renomme » une image : WordPress ne sait pas renommer un fichier par
     * son API, l'image est donc téléversée à nouveau sous le nouveau nom, avec
     * ses textes (alt, légende, description). L'ancien fichier reste dans la
     * médiathèque, intact : d'autres articles peuvent l'utiliser.
     *
     * Le fichier d'origine (pleine taille) est repris quand le média est
     * connu, sinon l'URL de l'image telle qu'insérée dans l'article.
     *
     * @return array<string, mixed> le nouveau média
     *
     * @throws WordPressApiException
     */
    public function renameCopy(WordpressSite $site, string $src, ?int $mediaId, string $name): array
    {
        $original = $mediaId ? $this->api->fetchMedia($site, $mediaId) : null;
        $sourceUrl = is_string($original['source_url'] ?? null) ? $original['source_url'] : $src;

        $slug = Str::limit(Str::slug($name), 80, '');

        if ($slug === '') {
            throw new WordPressApiException('Nom de fichier invalide : utilisez des lettres, des chiffres et des tirets.', 'invalid_name', 422);
        }

        try {
            $this->guard->assertSafe($sourceUrl);
        } catch (UnsafeUrlException $e) {
            throw new WordPressApiException('Adresse de l’image refusée.', 'unsafe_url', null, ['url' => $sourceUrl], $e);
        }

        try {
            $response = Http::withHeaders(['User-Agent' => config('articleguard.http.user_agent')])
                ->timeout((int) config('articleguard.images.download_timeout', 12) + 18)
                ->connectTimeout((int) config('articleguard.http.connect_timeout', 8))
                ->get($sourceUrl);
        } catch (ConnectionException $e) {
            throw WordPressApiException::unreachable($sourceUrl, $e);
        }

        $contents = $response->successful() ? $response->body() : '';
        $info = $contents !== '' ? @getimagesizefromstring($contents) : false;

        if ($info === false || strlen($contents) > (int) config('articleguard.images.max_bytes', 8 * 1024 * 1024)) {
            throw new WordPressApiException('Impossible de récupérer l’image d’origine pour la renommer.', 'image_unavailable', null, ['url' => $sourceUrl]);
        }

        $extension = strtolower(pathinfo((string) parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION))
            ?: (image_type_to_extension($info[2], false) ?: 'jpg');

        $media = $this->api->uploadMedia($site, $contents, $slug.'.'.$extension, (string) $info['mime']);

        // Les textes du média d'origine suivent la copie ; le titre reprend le
        // nouveau nom, comme le ferait WordPress pour un fichier téléversé.
        $texts = array_filter([
            'title' => trim($name),
            'alt_text' => (string) ($original['alt_text'] ?? ''),
            'caption' => $this->plain($original['caption'] ?? null),
            'description' => $this->plain($original['description'] ?? null),
        ], fn (string $value) => $value !== '');

        if ($texts !== [] && isset($media['id'])) {
            try {
                $media = $this->api->updateMedia($site, (int) $media['id'], $texts);
            } catch (WordPressApiException) {
                // Le fichier est bien renommé : seuls les textes manquent.
            }
        }

        return $this->present($media);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(WordpressSite $site, int $mediaId): ?array
    {
        $media = $this->api->fetchMedia($site, $mediaId);

        return $media ? $this->present($media) : null;
    }

    /**
     * Enregistre les champs du panneau « Détails du fichier joint ».
     *
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    public function updateDetails(WordpressSite $site, int $mediaId, array $attributes): array
    {
        $payload = array_intersect_key($attributes, array_flip([
            'alt_text', 'title', 'caption', 'description',
        ]));

        return $this->present($this->api->updateMedia($site, $mediaId, $payload));
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    protected function present(array $media): array
    {
        $details = is_array($media['media_details'] ?? null) ? $media['media_details'] : [];
        $sizes = is_array($details['sizes'] ?? null) ? $details['sizes'] : [];
        $thumbnail = $sizes['thumbnail']['source_url']
            ?? $sizes['medium']['source_url']
            ?? ($media['source_url'] ?? null);
        $url = (string) ($media['source_url'] ?? '');

        return [
            'id' => (int) ($media['id'] ?? 0),
            'url' => $url,
            'thumbnail' => is_string($thumbnail) ? $thumbnail : $url,
            'alt' => (string) ($media['alt_text'] ?? ''),
            'mime' => (string) ($media['mime_type'] ?? ''),
            'width' => isset($details['width']) ? (int) $details['width'] : null,
            'height' => isset($details['height']) ? (int) $details['height'] : null,
            'title' => $this->plain($media['title'] ?? null),
            'caption' => $this->plain($media['caption'] ?? null),
            'description' => $this->plain($media['description'] ?? null),
            'filename' => $this->filenameOf($details, $url),
            'uploaded_at' => (string) ($media['date'] ?? ''),
            'sizes' => $this->presentSizes($sizes, $details, $url),
        ];
    }

    /**
     * Déclinaisons proposées par WordPress à l'insertion (miniature, moyenne,
     * grande, taille originale). L'ordre suit celui de l'éditeur WordPress.
     *
     * @param  array<string, mixed>  $sizes
     * @param  array<string, mixed>  $details
     * @return array<int, array<string, mixed>>
     */
    protected function presentSizes(array $sizes, array $details, string $url): array
    {
        $labels = [
            'thumbnail' => 'Miniature',
            'medium' => 'Moyenne',
            'medium_large' => 'Moyenne-grande',
            'large' => 'Grande',
            'full' => 'Taille originale',
        ];

        $presented = [];

        foreach ($labels as $name => $label) {
            $size = is_array($sizes[$name] ?? null) ? $sizes[$name] : null;

            if ($name === 'full') {
                $size ??= [
                    'source_url' => $url,
                    'width' => $details['width'] ?? null,
                    'height' => $details['height'] ?? null,
                ];
            }

            if (! $size || ! is_string($size['source_url'] ?? null)) {
                continue;
            }

            $presented[] = [
                'name' => $name,
                'label' => $label,
                'url' => $size['source_url'],
                'width' => isset($size['width']) ? (int) $size['width'] : null,
                'height' => isset($size['height']) ? (int) $size['height'] : null,
            ];
        }

        return $presented;
    }

    /**
     * WordPress renvoie ces champs sous la forme `['rendered' => '<p>…</p>']`.
     */
    protected function plain(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['raw'] ?? $value['rendered'] ?? '';
        }

        return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function filenameOf(array $details, string $url): string
    {
        $file = is_string($details['file'] ?? null) ? basename($details['file']) : '';

        if ($file !== '') {
            return $file;
        }

        return basename((string) parse_url($url, PHP_URL_PATH));
    }

    /**
     * WordPress reprend le nom du fichier tel quel : on le normalise pour ne pas
     * créer d'entrée avec des caractères problématiques.
     */
    protected function safeFilename(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        if ($name === '') {
            $name = 'image-'.now()->format('YmdHis');
        }

        return Str::limit($name, 80, '').'.'.$extension;
    }
}
