<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Issue;
use App\Support\HtmlContent;

/**
 * Détection 5 — shortcodes et crochets résiduels.
 *
 * Un shortcode laissé par une extension désinstallée s'affiche tel quel sur le
 * site public. Le même défaut se présente sous des formes qui ne respectent pas
 * la syntaxe WordPress — `[public; text...script etc]` typiquement — et reste
 * tout aussi visible. La règle signale donc **tout crochet** présent dans le
 * texte, qu'il forme un shortcode valide ou non.
 *
 * Le titre subit le même contrôle : un crochet y est encore plus exposé
 * puisqu'il apparaît dans les résultats de recherche.
 */
class ShortcodeRule implements AuditRule
{
    public function key(): string
    {
        return 'shortcode';
    }

    public function label(): string
    {
        return 'Shortcode / crochet';
    }

    public function requiresNetwork(): bool
    {
        return false;
    }

    public function issueTypes(): array
    {
        return ['shortcode_detected'];
    }

    public function evaluate(AuditContext $context): array
    {
        return array_values(array_filter([
            $this->inContent($context),
            $this->inTitle($context),
        ]));
    }

    protected function inContent(AuditContext $context): ?Issue
    {
        $shortcodes = $context->html()->shortcodes();
        $brackets = $context->html()->brackets();

        if ($shortcodes === [] && $brackets === []) {
            return null;
        }

        $tags = array_values(array_unique(array_column($shortcodes, 'tag')));

        return Issue::info(
            'shortcode_detected',
            // Un shortcode reconnu est nommé comme tel ; un résidu de forme
            // libre ne l'est pas, sous peine d'induire en erreur.
            $tags !== [] ? 'Shortcode détecté' : 'Crochet détecté',
            [
                'target' => 'content',
                'count' => max(count($shortcodes), count($brackets)),
                'tags' => $tags,
                'samples' => $shortcodes !== []
                    ? array_slice(array_column($shortcodes, 'raw'), 0, 5)
                    : $brackets,
            ]
        );
    }

    protected function inTitle(AuditContext $context): ?Issue
    {
        $brackets = HtmlContent::bracketsIn($context->title());

        if ($brackets === []) {
            return null;
        }

        return Issue::warning(
            'shortcode_detected',
            'Crochet détecté dans le titre',
            [
                'target' => 'title',
                'count' => count($brackets),
                'tags' => [],
                'samples' => $brackets,
            ]
        );
    }
}
