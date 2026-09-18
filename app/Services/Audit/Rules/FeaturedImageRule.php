<?php

namespace App\Services\Audit\Rules;

use App\Models\ImageAnalysis;
use App\Services\Audit\AuditContext;
use App\Services\Audit\ImageQualityAnalyzer;
use App\Services\Audit\Issue;

/**
 * Détection 1 — image mise en avant.
 *
 * `featured_media === 0` signifie qu'aucune image à la une n'est définie.
 * Si elle est définie, on vérifie que le média est bien résolu et accessible.
 */
class FeaturedImageRule implements AuditRule
{
    public function __construct(
        protected ImageQualityAnalyzer $analyzer,
    ) {}

    public function key(): string
    {
        return 'featured_image';
    }

    public function label(): string
    {
        return 'Image à la une';
    }

    public function requiresNetwork(): bool
    {
        return false;
    }

    public function issueTypes(): array
    {
        return ['featured_image_missing', 'featured_image_unresolved', 'featured_image_unreachable'];
    }

    public function evaluate(AuditContext $context): array
    {
        $article = $context->article;

        if ((int) $article->featured_media_id === 0) {
            return [Issue::warning(
                'featured_image_missing',
                'Image à la une manquante',
                ['target' => 'featured_image']
            )];
        }

        if (blank($article->featured_media_url)) {
            return [Issue::warning(
                'featured_image_unresolved',
                'Image à la une introuvable dans la médiathèque',
                ['target' => 'featured_image', 'media_id' => (int) $article->featured_media_id]
            )];
        }

        // L'accessibilité réelle s'appuie sur l'analyse mise en cache : aucune
        // requête supplémentaire n'est déclenchée ici.
        $analysis = $context->allowNetwork
            ? $this->analyzer->analyze($article->featured_media_url)
            : $this->analyzer->cached($article->featured_media_url);

        if ($analysis && in_array($analysis->status, [ImageAnalysis::STATUS_UNREACHABLE, ImageAnalysis::STATUS_BLOCKED], true)) {
            return [Issue::error(
                'featured_image_unreachable',
                'Image à la une inaccessible',
                [
                    'target' => 'featured_image',
                    'src' => $article->featured_media_url,
                    'error' => $analysis->error_code,
                ]
            )];
        }

        return [];
    }
}
