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
     * Libellés courts des problèmes, pour le résumé en tête de la colonne :
     * « 2 × Image potentiellement floue », « Titre trop long »…
     *
     * @param  Collection<int, ArticleAuditIssue>  $issues
     * @return array<int, array{message: string, count: int, severity: string}>
     */
    public static function summary(Collection $issues): array
    {
        return $issues
            ->groupBy('message')
            ->map(fn (Collection $group, string $message) => [
                'message' => $message,
                'count' => $group->count(),
                'severity' => $group->contains('severity', ArticleAuditIssue::SEVERITY_ERROR)
                    ? 'error'
                    : ($group->contains('severity', ArticleAuditIssue::SEVERITY_WARNING) ? 'warning' : 'info'),
            ])
            ->values()
            ->all();
    }

    /**
     * Détail lisible d'un problème : ce qui est en cause, les valeurs mesurées
     * et la piste de correction.
     *
     * @return array{title: string, src: ?string, file: ?string, where: ?string, details: array<int, string>, hint: ?string, target: string}
     */
    public static function describe(ArticleAuditIssue $issue, AuditSettings $settings): array
    {
        $meta = $issue->metadata ?? [];
        $src = is_string($meta['src'] ?? null) ? $meta['src'] : null;
        $details = [];
        $hint = null;
        $alt = trim((string) ($meta['alt'] ?? ''));

        switch ($issue->rule_type) {
            case 'image_blurry':
                if (isset($meta['sharpness'])) {
                    $details[] = sprintf(
                        'Netteté mesurée : %s (seuil : %s)',
                        self::number($meta['sharpness']),
                        self::number($meta['threshold'] ?? $settings->threshold('blur', 100)),
                    );
                }
                if (! empty($meta['width']) && ! empty($meta['height'])) {
                    $details[] = sprintf('Dimensions : %d × %d px', $meta['width'], $meta['height']);
                }
                $hint = 'Détection heuristique : vérifiez l’image et remplacez-la par une version plus nette si besoin.';
                break;

            case 'image_low_resolution':
                $details[] = sprintf(
                    'Dimensions : %d × %d px (minimum conseillé : %d × %d px)',
                    $meta['width'] ?? 0,
                    $meta['height'] ?? 0,
                    $meta['min_width'] ?? 0,
                    $meta['min_height'] ?? 0,
                );
                $hint = 'Remplacez-la par une image de plus grande taille.';
                break;

            case 'image_possibly_incoherent':
                if (! empty($meta['image_terms'])) {
                    $details[] = 'Mots décrivant l’image : '.implode(', ', array_slice($meta['image_terms'], 0, 6));
                }
                $details[] = empty($meta['matched_terms'])
                    ? 'Aucun de ces mots ne figure dans l’article.'
                    : 'Mots communs avec l’article : '.implode(', ', array_slice($meta['matched_terms'], 0, 6));
                if (isset($meta['score'])) {
                    $details[] = sprintf(
                        'Cohérence estimée : %d %% (seuil : %d %%)',
                        round((float) $meta['score'] * 100),
                        round((float) $settings->threshold('relevance', 0.35) * 100),
                    );
                }
                $hint = 'Vérifiez que l’image illustre bien le sujet, ou précisez son texte alternatif.';
                break;

            case 'body_image_broken':
            case 'featured_image_unreachable':
                $details[] = 'L’image ne peut pas être téléchargée'.(filled($meta['error'] ?? null) ? ' ('.$meta['error'].')' : '').'.';
                $hint = 'Remplacez l’image ou retirez-la.';
                break;

            case 'featured_image_missing':
                $hint = 'Choisissez une image à la une dans la colonne « Image mise en avant ».';
                break;

            case 'featured_image_unresolved':
                $details[] = 'Média n° '.($meta['media_id'] ?? '?').' introuvable dans la médiathèque WordPress.';
                break;

            case 'body_image_missing':
                $hint = 'Insérez au moins une image dans le contenu.';
                break;

            case 'long_title':
                if (isset($meta['words'], $meta['max'])) {
                    $details[] = sprintf('%d mots pour %d maximum', $meta['words'], $meta['max']);
                }
                $hint = 'Raccourcissez le titre.';
                break;

            case 'shortcode_detected':
                foreach (array_slice((array) ($meta['samples'] ?? []), 0, 3) as $sample) {
                    $details[] = (string) $sample;
                }
                break;

            case 'multiple_h1':
                foreach (array_slice((array) ($meta['headings'] ?? []), 0, 5) as $heading) {
                    $details[] = 'H1 : '.($heading !== '' ? $heading : '(vide)');
                }
                $hint = 'Conservez un seul H1 : le titre de l’article en tient déjà lieu, passez les autres en H2.';
                break;

            case 'missing_h2':
                $hint = 'Structurez le contenu avec des intertitres H2.';
                break;
        }

        if ($src !== null && $alt !== '') {
            array_unshift($details, 'Texte alternatif : '.$alt);
        }

        $where = match ($meta['scope'] ?? null) {
            'featured' => 'Image à la une',
            'content' => 'Image du contenu',
            default => null,
        };

        return [
            'title' => $issue->message,
            'src' => $src,
            'file' => $src ? self::fileName($src) : null,
            'where' => $where,
            'details' => $details,
            'hint' => $hint,
            'target' => ($meta['target'] ?? null) === 'featured_image' ? 'featured_image' : ($src ? 'images' : (string) ($meta['target'] ?? 'content')),
        ];
    }

    protected static function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, ',', ' '), '0'), ',');
    }

    protected static function fileName(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return rawurldecode(basename($path)) ?: $url;
    }

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
