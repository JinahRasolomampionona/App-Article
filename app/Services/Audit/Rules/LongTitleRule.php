<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Issue;

/**
 * Détection 6 — H1 (titre de l'article) trop long.
 *
 * Le titre WordPress est ce que le thème rend en `<h1>` sur la page publique :
 * c'est à ce titre que sa longueur est contrôlée.
 *
 * La mesure porte sur le **nombre de mots**, et non sur le nombre de
 * caractères : un titre de dix mots courts reste lisible là où la même longueur
 * en caractères peut cacher trois mots interminables. Seuil par défaut :
 * 20 mots, configurable.
 *
 * Le titre n'est jamais tronqué automatiquement : la règle se contente de
 * signaler pour permettre une correction manuelle.
 */
class LongTitleRule implements AuditRule
{
    public function key(): string
    {
        return 'long_title';
    }

    public function label(): string
    {
        return 'Longueur du H1 (titre)';
    }

    public function requiresNetwork(): bool
    {
        return false;
    }

    public function issueTypes(): array
    {
        return ['long_title'];
    }

    public function evaluate(AuditContext $context): array
    {
        $max = (int) $context->settings->threshold('title_max_words', 20);
        $title = html_entity_decode(strip_tags($context->title()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = self::countWords($title);

        if ($words <= $max) {
            return [];
        }

        return [Issue::warning(
            'long_title',
            'H1 trop long (max : '.$max.' mots)',
            [
                'target' => 'title',
                'words' => $words,
                'max' => $max,
                // Conservée à titre indicatif pour l'écran d'édition.
                'length' => mb_strlen(trim($title)),
            ]
        )];
    }

    /**
     * Compte les mots d'un titre.
     *
     * Le découpage se fait sur les espaces : « aujourd'hui » et « porte-clés »
     * comptent chacun pour un mot, comme le ferait un lecteur. Un séparateur
     * isolé (« : », « — », « | ») n'est pas compté.
     */
    public static function countWords(string $title): int
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        if ($title === '') {
            return 0;
        }

        return count(array_filter(
            explode(' ', $title),
            fn (string $word) => preg_match('/[\p{L}\p{N}]/u', $word) === 1
        ));
    }
}
