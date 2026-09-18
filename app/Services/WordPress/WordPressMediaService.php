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
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    protected function present(array $media): array
    {
        $details = $media['media_details'] ?? [];
        $sizes = is_array($details['sizes'] ?? null) ? $details['sizes'] : [];
        $thumbnail = $sizes['thumbnail']['source_url']
            ?? $sizes['medium']['source_url']
            ?? ($media['source_url'] ?? null);

        return [
            'id' => (int) ($media['id'] ?? 0),
            'url' => (string) ($media['source_url'] ?? ''),
            'thumbnail' => is_string($thumbnail) ? $thumbnail : (string) ($media['source_url'] ?? ''),
            'alt' => (string) ($media['alt_text'] ?? ''),
            'mime' => (string) ($media['mime_type'] ?? ''),
            'width' => isset($details['width']) ? (int) $details['width'] : null,
            'height' => isset($details['height']) ? (int) $details['height'] : null,
            'title' => is_array($media['title'] ?? null)
                ? strip_tags((string) ($media['title']['rendered'] ?? ''))
                : (string) ($media['title'] ?? ''),
        ];
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
