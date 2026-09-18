<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Issue;

/**
 * Détection 2 — image dans le contenu.
 *
 * Distincte de l'image mise en avant : un article peut avoir une image à la une
 * et aucune illustration dans son corps, et inversement.
 */
class BodyImageRule implements AuditRule
{
    public function key(): string
    {
        return 'body_image';
    }

    public function label(): string
    {
        return 'Image dans le contenu';
    }

    public function requiresNetwork(): bool
    {
        return false;
    }

    public function issueTypes(): array
    {
        return ['body_image_missing'];
    }

    public function evaluate(AuditContext $context): array
    {
        $images = $context->html()->images();

        if ($images !== []) {
            return [];
        }

        return [Issue::warning(
            'body_image_missing',
            'Image dans le contenu manquante',
            ['target' => 'content']
        )];
    }
}
