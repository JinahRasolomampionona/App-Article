<?php

namespace App\Services\WordPress;

use App\Models\WordpressSite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Accès à la médiathèque WordPress depuis l'éditeur d'article.
 */
class WordPressMediaService
{
    public function __construct(
        protected WordPressApiService $api,
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
