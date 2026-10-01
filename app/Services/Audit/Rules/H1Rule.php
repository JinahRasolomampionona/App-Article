<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Issue;

/**
 * Détection 7 — balises H1.
 *
 * Une page d'article ne doit compter qu'un seul H1 : le titre WordPress, que le
 * thème rend en dehors du champ `content`. Tout H1 présent dans le corps de
 * l'article en ajoute donc un second sur la page : c'est une erreur, signalée
 * dès le premier H1 du contenu (« Problème balise H1 »).
 *
 * - 0 H1 dans le contenu : conforme (le titre tient lieu de H1). Signalé
 *   uniquement si la règle optionnelle `missing_h1` est activée, pour les
 *   thèmes qui n'affichent pas le titre en H1 ;
 * - 1 H1 ou plus dans le contenu : toujours signalé.
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

        if ($count >= 1) {
            // Le titre de l'article + les H1 du contenu.
            $onPage = $count + 1;

            return [Issue::error(
                'multiple_h1',
                'Problème balise H1 : '.$onPage.' H1 sur la page',
                [
                    'target' => 'content',
                    'count' => $count,
                    'on_page' => $onPage,
                    'headings' => array_slice($headings, 0, 5),
                ]
            )];
        }

        if ($context->settings->ruleEnabled('missing_h1')) {
            return [Issue::info(
                'missing_h1',
                'H1 manquant',
                ['target' => 'content', 'count' => 0]
            )];
        }

        return [];
    }
}
