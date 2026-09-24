<?php

namespace App\Services\Audit\Rules;

use App\Models\ImageAnalysis;
use App\Services\Audit\AuditContext;
use App\Services\Audit\ImageQualityAnalyzer;
use App\Services\Audit\Issue;
use App\Services\Audit\Rules\Concerns\SelectsContentImages;

/**
 * Détection 3 — images potentiellement floues ou de trop faible résolution.
 *
 * Règle coûteuse (téléchargement des images) : elle n'est exécutée que dans un
 * job en file d'attente, et s'appuie sur le cache d'analyses pour ne jamais
 * télécharger deux fois la même URL.
 *
 * Seules les images du contenu sont analysées : la section hero (image à la
 * une affichée en bandeau par le thème) est écartée.
 */
class ImageBlurRule implements AuditRule
{
    use SelectsContentImages;

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
        $minWidth = (int) $context->settings->threshold('min_image_width', 600);
        $minHeight = (int) $context->settings->threshold('min_image_height', 400);
        $blurThreshold = (float) $context->settings->threshold('blur', 100);

        foreach ($this->analyzableImages($context) as $target) {
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
                        'alt' => $target['alt'],
                        'sharpness' => $analysis->sharpness,
                        'threshold' => $blurThreshold,
                        'width' => $analysis->width,
                        'height' => $analysis->height,
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
                        'alt' => $target['alt'],
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
}
