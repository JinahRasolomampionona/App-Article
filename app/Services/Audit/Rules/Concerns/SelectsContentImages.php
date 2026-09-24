<?php

namespace App\Services\Audit\Rules\Concerns;

use App\Services\Audit\AuditContext;

/**
 * Images soumises aux analyses de flou et de cohérence.
 *
 * Seules les images du corps de l'article sont retenues. La section « hero »
 * — le bandeau d'en-tête — est écartée :
 *  - l'image à la une, que le thème affiche en bandeau au-dessus de l'article ;
 *  - une image du contenu placée dans un bloc hero ou « Couverture » ;
 *  - une image du contenu qui n'est qu'une autre taille de l'image à la une.
 *
 * `articleguard.images.analyze_featured_image` réintègre l'image à la une.
 */
trait SelectsContentImages
{
    /**
     * @return array<int, array{src: string, alt: string, title: string, caption: string, target: string, scope: string}>
     */
    protected function analyzableImages(AuditContext $context): array
    {
        $targets = [];
        $seen = [];
        $featured = (string) $context->article->featured_media_url;

        if ($featured !== '' && config('articleguard.images.analyze_featured_image', false)) {
            $seen[$featured] = true;
            $targets[] = [
                'src' => $featured,
                'alt' => (string) $context->article->featured_media_alt,
                'title' => '',
                'caption' => '',
                'target' => 'featured_image',
                'scope' => 'featured',
            ];
        }

        $heroKey = $featured !== '' ? $this->mediaKey($featured) : null;
        $max = (int) config('articleguard.images.max_body_images', 6);
        $count = 0;

        foreach ($context->html()->images() as $image) {
            if ($count >= $max) {
                break;
            }

            if ($image['in_hero'] || isset($seen[$image['src']])
                || ($heroKey !== null && $this->mediaKey($image['src']) === $heroKey)) {
                continue;
            }

            $seen[$image['src']] = true;
            $count++;
            $targets[] = [
                'src' => $image['src'],
                'alt' => $image['alt'],
                'title' => $image['title'],
                'caption' => $image['caption'],
                'target' => $image['src'],
                'scope' => 'content',
            ];
        }

        return $targets;
    }

    /**
     * Identité d'un média indépendante de sa taille : WordPress décline une
     * image en `photo-1024x768.jpg`, `photo-scaled.jpg`… qui restent la même.
     */
    protected function mediaKey(string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_replace('/(-\d{2,5}x\d{2,5}|-scaled)+(?=\.[a-z0-9]+$)/', '', $path) ?? $path;
    }
}
