<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Issue;

/**
 * Détection 7 — balises H1 du contenu.
 *
 * Le titre WordPress n'est pas compté : il est rendu par le thème, en dehors du
 * champ `content`. Seuls les H1 réellement présents dans le corps de l'article
 * sont pris en compte.
 *
 * - 0 H1 : signalé uniquement si la règle optionnelle `missing_h1` est activée ;
 * - 1 H1 : conforme ;
 * - 2 H1 ou plus : toujours signalé.
 */
class H1Rule implements AuditRule
{
    public function key(): string
    {
        return 'h1';
    }

    public function label(): string
    {
        return 'Structure H1';
    }

    public function requiresNetwork(): bool
    {
        return false;
    }

    public function issueTypes(): array
    {
        return ['multiple_h1', 'missing_h1'];
    }

    public function evaluate(AuditContext $context): array
    {
        $headings = $context->html()->headings(1);
        $count = count($headings);

        if ($count >= 2) {
            return [Issue::error(
                'multiple_h1',
                $count.' balises H1 détectées',
                [
                    'target' => 'content',
                    'count' => $count,
                    'headings' => array_slice($headings, 0, 5),
                ]
            )];
        }

        if ($count === 0 && $context->settings->ruleEnabled('missing_h1')) {
            return [Issue::info(
                'missing_h1',
                'H1 manquant',
                ['target' => 'content', 'count' => 0]
            )];
        }

        return [];
    }
}
