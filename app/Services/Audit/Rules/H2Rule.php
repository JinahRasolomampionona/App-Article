<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Issue;

/**
 * Règle optionnelle — absence de sous-titre H2.
 *
 * Désactivée par défaut : un article court peut légitimement ne pas être
 * découpé en sections.
 */
class H2Rule implements AuditRule
{
    public function key(): string
    {
        return 'missing_h2';
    }

    public function label(): string
    {
        return 'Structure H2';
    }

    public function requiresNetwork(): bool
    {
        return false;
    }

    public function issueTypes(): array
    {
        return ['missing_h2'];
    }

    public function evaluate(AuditContext $context): array
    {
        if ($context->html()->headings(2) !== []) {
            return [];
        }

        return [Issue::info(
            'missing_h2',
            'H2 manquant',
            ['target' => 'content', 'count' => 0]
        )];
    }
}
