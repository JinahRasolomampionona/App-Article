<?php

namespace App\Services\Audit\Rules;

use App\Models\ImageAnalysis;
use App\Services\Audit\AuditContext;
use App\Services\Audit\ImageQualityAnalyzer;
use App\Services\Audit\Issue;

/**
 * Détection 3 — images potentiellement floues ou de trop faible résolution.
 *
 * Règle coûteuse (téléchargement des images) : elle n'est exécutée que dans un
 * job en file d'attente, et s'appuie sur le cache d'analyses pour ne jamais
 * télécharger deux fois la même URL.
 */
class ImageBlurRule implements AuditRule
{
    public function __construct(
        protected ImageQualityAnalyzer $analyzer,
    ) {}

    public function key(): string
    {
        return 'image_blur';
    }

    public function label(): string
    {
        return 'Netteté des images';
    }

    public function requiresNetwork(): bool
    {
        return true;
    }

    public function issueTypes(): array
    {
        return ['image_blurry', 'image_low_resolution'];
    }

    public function evaluate(AuditContext $context): array
    {
        $issues = [];
        $maxImages = (int) config('articleguard.images.max_body_images', 6);
        $minWidth = (int) $context->settings->threshold('min_image_width', 600);
        $minHeight = (int) $context->settings->threshold('min_image_height', 400);
        $blurThreshold = (float) $context->settings->threshold('blur', 100);

        foreach ($this->targets($context, $maxImages) as $target) {
            $analysis = $context->allowNetwork
                ? $this->analyzer->analyze($target['src'])
                : $this->analyzer->cached($target['src']);

            if ($analysis === null || $analysis->status !== ImageAnalysis::STATUS_OK) {
                continue;
            }

            if ($analysis->sharpness !== null && $analysis->sharpness < $blurThreshold) {
                $issues[] = Issue::warning(
                    'image_blurry',
                    'Image potentiellement floue',
                    [
                        'target' => $target['target'],
                        'src' => $target['src'],
                        'sharpness' => $analysis->sharpness,
                        'threshold' => $blurThreshold,
                        'scope' => $target['scope'],
                    ]
                );

                continue;
            }

            if ($analysis->width !== null && $analysis->height !== null
                && ($analysis->width < $minWidth || $analysis->height < $minHeight)) {
                $issues[] = Issue::info(
                    'image_low_resolution',
                    'Image de faible résolution',
                    [
                        'target' => $target['target'],
                        'src' => $target['src'],
                        'width' => $analysis->width,
                        'height' => $analysis->height,
                        'min_width' => $minWidth,
                        'min_height' => $minHeight,
                        'scope' => $target['scope'],
                    ]
                );
            }
        }

        return $issues;
    }

    /**
     * Image à la une puis images du contenu, dédoublonnées par URL.
     *
     * @return array<int, array{src: string, target: string, scope: string}>
     */
    protected function targets(AuditContext $context, int $maxBodyImages): array
    {
        $targets = [];
        $seen = [];

        if (filled($context->article->featured_media_url)) {
            $src = (string) $context->article->featured_media_url;
            $seen[$src] = true;
            $targets[] = ['src' => $src, 'target' => 'featured_image', 'scope' => 'featured'];
        }

        foreach (array_slice($context->html()->images(), 0, $maxBodyImages) as $image) {
            if (isset($seen[$image['src']])) {
                continue;
            }

            $seen[$image['src']] = true;
            $targets[] = ['src' => $image['src'], 'target' => $image['src'], 'scope' => 'content'];
        }

        return $targets;
    }
}
