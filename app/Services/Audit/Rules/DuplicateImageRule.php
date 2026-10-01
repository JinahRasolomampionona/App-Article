<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Issue;
use App\Services\Audit\Rules\Concerns\SelectsContentImages;

/**
 * Images en double dans le contenu.
 *
 * Une même image insérée deux fois (ou plus) dans le corps de l'article se
 * répète sous les yeux du lecteur : c'est presque toujours un oubli lors d'un
 * remplacement. Les déclinaisons de taille WordPress (`photo-1024x768.jpg`,
 * `photo-scaled.jpg`…) désignent le même média et comptent comme doublons.
 *
 * Une remarque par image répétée, avec le nombre d'occurrences et leur
 * position dans le contenu (« images 2 et 5 »).
 */
class DuplicateImageRule implements AuditRule
{
    use SelectsContentImages;

    public function key(): string
    {
        return 'duplicate_image';
    }

    public function label(): string
    {
        return 'Images en double';
    }

    public function requiresNetwork(): bool
    {
        return false;
    }

    public function issueTypes(): array
    {
        return ['duplicate_image'];
    }

    public function evaluate(AuditContext $context): array
    {
        /** @var array<string, array{src: string, alt: string, positions: array<int, int>}> $groups */
        $groups = [];

        foreach ($context->html()->images() as $index => $image) {
            $key = $this->mediaKey($image['src']);

            if ($key === '') {
                continue;
            }

            $groups[$key] ??= ['src' => $image['src'], 'alt' => $image['alt'], 'positions' => []];
            $groups[$key]['positions'][] = $index + 1;
        }

        $issues = [];

        foreach ($groups as $group) {
            $count = count($group['positions']);

            if ($count < 2) {
                continue;
            }

            $issues[] = Issue::warning(
                'duplicate_image',
                'Image en double ('.$count.' fois dans le contenu)',
                [
                    'target' => $group['src'],
                    'src' => $group['src'],
                    'alt' => $group['alt'],
                    'count' => $count,
                    'positions' => $group['positions'],
                    'scope' => 'content',
                ]
            );
        }

        return $issues;
    }
}
