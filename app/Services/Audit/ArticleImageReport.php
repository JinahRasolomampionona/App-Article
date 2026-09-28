<?php

namespace App\Services\Audit;

use App\Models\ImageAnalysis;
use App\Models\WordpressArticle;
use App\Support\HtmlContent;

/**
 * Toutes les images d'un article — image à la une et images du contenu —
 * avec, pour chacune, le verdict de qualité connu.
 *
 * Aucune image n'est téléchargée ici : les mesures viennent du cache
 * d'analyses alimenté par l'audit, et les verdicts « incohérente » / « cassée »
 * des remarques ouvertes. Une image jamais analysée est présentée comme telle
 * plutôt que comme « bonne ».
 */
class ArticleImageReport
{
    /** Remarques d'audit rattachées à une image précise. */
    protected const IMAGE_ISSUES = [
        'featured_image_unreachable',
        'body_image_broken',
        'image_blurry',
        'image_low_resolution',
        'image_possibly_incoherent',
    ];

    /**
     * @return array<int, array{
     *     scope: string, src: string, display_url: ?string, alt: string,
     *     width: ?int, height: ?int, sharpness: ?float, bytes: ?int,
     *     verdicts: array<int, array{label: string, variant: string, icon: string, detail: ?string}>
     * }>
     */
    public function build(WordpressArticle $article): array
    {
        $candidates = [];
        $featured = (string) $article->featured_media_url;

        if ($featured !== '') {
            $candidates[] = ['scope' => 'featured', 'src' => $featured, 'alt' => (string) $article->featured_media_alt, 'in_hero' => false];
        }

        $seen = [$featured => true];

        foreach (HtmlContent::make($article->content)->images() as $image) {
            if (isset($seen[$image['src']])) {
                continue;
            }

            $seen[$image['src']] = true;
            $candidates[] = ['scope' => 'content', 'src' => $image['src'], 'alt' => $image['alt'], 'in_hero' => $image['in_hero']];
        }

        if ($candidates === []) {
            return [];
        }

        // Une seule requête pour toutes les analyses de l'article.
        $analyses = ImageAnalysis::query()
            ->whereIn('url_hash', array_map(fn ($c) => ImageAnalysis::hashFor($c['src']), $candidates))
            ->get()
            ->keyBy('url_hash');

        $issues = ($article->relationLoaded('openIssues') ? $article->openIssues : $article->openIssues()->get())
            ->whereIn('rule_type', self::IMAGE_ISSUES);

        $settings = AuditSettings::forUser($article->site?->user);
        $base = (string) $article->site?->url;

        return array_map(function (array $candidate) use ($analyses, $issues, $settings, $base) {
            $analysis = $analyses->get(ImageAnalysis::hashFor($candidate['src']));

            $related = $issues->filter(fn ($issue) => $candidate['scope'] === 'featured'
                ? ($issue->metadata['target'] ?? null) === 'featured_image'
                : ($issue->metadata['src'] ?? null) === $candidate['src']);

            return [
                'scope' => $candidate['scope'],
                'src' => $candidate['src'],
                'display_url' => self::displayUrl($candidate['src'], $base),
                'alt' => $candidate['alt'],
                'in_hero' => $candidate['in_hero'],
                'width' => $analysis?->width,
                'height' => $analysis?->height,
                'sharpness' => $analysis?->sharpness,
                'bytes' => $analysis?->bytes,
                'verdicts' => $this->verdicts($analysis, $related->pluck('rule_type')->all(), $settings),
            ];
        }, $candidates);
    }

    /**
     * @param  array<int, string>  $issueTypes
     * @return array<int, array{label: string, variant: string, icon: string, detail: ?string}>
     */
    protected function verdicts(?ImageAnalysis $analysis, array $issueTypes, AuditSettings $settings): array
    {
        $verdicts = [];

        if (array_intersect($issueTypes, ['featured_image_unreachable', 'body_image_broken'])
            || in_array($analysis?->status, [ImageAnalysis::STATUS_UNREACHABLE, ImageAnalysis::STATUS_BLOCKED], true)) {
            return [['label' => 'Image cassée / inaccessible', 'variant' => 'danger', 'icon' => 'bi-x-octagon', 'detail' => $analysis?->error_code]];
        }

        if (in_array('image_possibly_incoherent', $issueTypes, true)) {
            $verdicts[] = ['label' => 'Potentiellement incohérente', 'variant' => 'warning', 'icon' => 'bi-question-diamond', 'detail' => 'avec le sujet de l’article'];
        }

        if ($analysis === null || $analysis->status !== ImageAnalysis::STATUS_OK) {
            $verdicts[] = $analysis === null
                ? ['label' => 'Non analysée', 'variant' => 'muted', 'icon' => 'bi-hourglass', 'detail' => 'Relancez l’audit pour mesurer la qualité.']
                : ['label' => 'Analyse impossible', 'variant' => 'muted', 'icon' => 'bi-slash-circle', 'detail' => $analysis->error_code ?? $analysis->status];

            return $verdicts;
        }

        // Mêmes seuils que l'audit (ImageBlurRule).
        $blur = (float) $settings->threshold('blur', 100);
        $minWidth = (int) $settings->threshold('min_image_width', 600);
        $minHeight = (int) $settings->threshold('min_image_height', 400);

        if ($analysis->sharpness !== null && $analysis->sharpness < $blur) {
            $verdicts[] = ['label' => 'Potentiellement floue', 'variant' => 'warning', 'icon' => 'bi-droplet-half',
                'detail' => 'Netteté '.round($analysis->sharpness).' (seuil '.round($blur).')'];
        }

        if ($analysis->width !== null && $analysis->height !== null
            && ($analysis->width < $minWidth || $analysis->height < $minHeight)) {
            $verdicts[] = ['label' => 'Faible résolution', 'variant' => 'warning', 'icon' => 'bi-aspect-ratio',
                'detail' => $analysis->width.'×'.$analysis->height.' px (min. '.$minWidth.'×'.$minHeight.')'];
        }

        if ($verdicts === []) {
            $verdicts[] = ['label' => 'Bonne qualité', 'variant' => 'success', 'icon' => 'bi-check2-circle',
                'detail' => $analysis->sharpness !== null ? 'Netteté '.round($analysis->sharpness) : null];
        }

        return $verdicts;
    }

    /**
     * URL affichable dans la page : seulement http(s), relatives résolues
     * contre le domaine du site. Toute autre forme (javascript:, data:…) est
     * écartée.
     */
    public static function displayUrl(string $src, string $siteUrl): ?string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (str_starts_with($src, '//')) {
            $src = 'https:'.$src;
        } elseif (str_starts_with($src, '/') && $siteUrl !== '') {
            $parts = parse_url($siteUrl);
            $src = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').$src;
        }

        return preg_match('#^https?://[^\s"\'<>]+$#i', $src) ? $src : null;
    }

    /**
     * Aperçu pour la ligne du tableau, sans parser le DOM : première image
     * (à la une en priorité) et nombre total d'images distinctes.
     *
     * $siteUrl évite de charger le site pour chaque ligne ; sans lui, seule
     * une URL absolue sert de miniature.
     *
     * @return array{thumb: ?string, count: int}
     */
    public static function preview(WordpressArticle $article, ?string $siteUrl = null): array
    {
        $urls = [];

        if (filled($article->featured_media_url)) {
            $urls[(string) $article->featured_media_url] = true;
        }

        if (preg_match_all('/<img\b[^>]*?\s(?:data-)?src\s*=\s*("([^"]*)"|\'([^\']*)\')/i', (string) $article->content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $src = trim(($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));

                if ($src !== '') {
                    $urls[$src] = true;
                }
            }
        }

        $first = array_key_first($urls);

        return [
            'thumb' => $first !== null ? self::displayUrl((string) $first, (string) $siteUrl) : null,
            'count' => count($urls),
        ];
    }
}
