<?php

namespace App\Support;

use App\Models\ArticleAuditIssue;
use App\Services\Audit\AuditSettings;
use Illuminate\Support\Collection;

/**
 * Construit la colonne « Audit de l'article » de l'éditeur.
 *
 * Chaque vérification est présentée avec son état : conforme, en anomalie, ou
 * non évaluée lorsque la règle est désactivée. Afficher une coche verte pour
 * une règle qui n'a jamais tourné serait trompeur.
 */
class AuditPanelPresenter
{
    /**
     * Vérifications affichées, dans l'ordre, avec les types de problèmes
     * qu'elles couvrent et la clé de règle correspondante.
     *
     * @var array<int, array{label: string, rule: string, types: array<int, string>, target: string}>
     */
    protected const CHECKS = [
        [
            'label' => 'Image à la une',
            'rule' => 'featured_image',
            'types' => ['featured_image_missing', 'featured_image_unresolved', 'featured_image_unreachable'],
            'target' => 'featured_image',
        ],
        [
            'label' => 'Image dans le contenu',
            'rule' => 'body_image',
            'types' => ['body_image_missing'],
            'target' => 'content',
        ],
        [
            'label' => 'Images cassées',
            'rule' => 'broken_image',
            'types' => ['body_image_broken'],
            'target' => 'content',
        ],
        [
            'label' => 'Longueur du H1 (titre)',
            'rule' => 'long_title',
            'types' => ['long_title'],
            'target' => 'title',
        ],
        [
            'label' => 'Shortcodes',
            'rule' => 'shortcode',
            'types' => ['shortcode_detected'],
            'target' => 'content',
        ],
        [
            'label' => 'Structure H1',
            'rule' => 'h1',
            'types' => ['multiple_h1', 'missing_h1'],
            'target' => 'content',
        ],
        [
            'label' => 'Structure H2',
            'rule' => 'missing_h2',
            'types' => ['missing_h2'],
            'target' => 'content',
        ],
        [
            'label' => 'Netteté des images',
            'rule' => 'image_blur',
            'types' => ['image_blurry', 'image_low_resolution'],
            'target' => 'images',
        ],
        [
            'label' => 'Cohérence des images',
            'rule' => 'image_relevance',
            'types' => ['image_possibly_incoherent'],
            'target' => 'images',
        ],
    ];

    /**
     * @param  Collection<int, ArticleAuditIssue>  $issues  problèmes ouverts
     * @return array<int, array{label: string, state: string, target: string, type: ?string, issues: array<int, ArticleAuditIssue>}>
     */
    public static function checks(Collection $issues, AuditSettings $settings): array
    {
        $byType = $issues->groupBy('rule_type');
        $checks = [];

        foreach (self::CHECKS as $check) {
            if (! $settings->ruleEnabled($check['rule'])) {
                $checks[] = $check + ['state' => 'disabled', 'issues' => [], 'type' => null];

                continue;
            }

            $matching = collect($check['types'])
                ->flatMap(fn (string $type) => $byType->get($type, collect())->all())
                ->values();

            if ($matching->isEmpty()) {
                $checks[] = $check + ['state' => 'ok', 'issues' => [], 'type' => null];

                continue;
            }

            // La sévérité la plus élevée détermine la couleur de la ligne.
            $state = $matching->contains(fn (ArticleAuditIssue $issue) => $issue->severity === ArticleAuditIssue::SEVERITY_ERROR)
                ? 'error'
                : ($matching->contains(fn (ArticleAuditIssue $issue) => $issue->severity === ArticleAuditIssue::SEVERITY_WARNING)
                    ? 'warning'
                    : 'info');

            $checks[] = $check + [
                'state' => $state,
                'issues' => $matching->all(),
                'type' => $matching->first()->rule_type,
            ];
        }

        return $checks;
    }
}
