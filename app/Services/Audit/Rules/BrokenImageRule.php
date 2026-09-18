<?php

namespace App\Services\Audit\Rules;

use App\Models\ImageAnalysis;
use App\Services\Audit\AuditContext;
use App\Services\Audit\ImageQualityAnalyzer;
use App\Services\Audit\Issue;

/**
 * Images du contenu qui ne s'affichent pas.
 *
 * Une image dont l'URL renvoie une 404, une erreur serveur, un corps vide ou
 * autre chose qu'une image apparaît cassée chez le visiteur : c'est le défaut
 * le plus visible de tous, et le plus silencieux côté back-office puisque le
 * HTML de l'article, lui, reste parfaitement valide.
 *
 * La règle ne juge pas la qualité — c'est le rôle d'`ImageBlurRule`, qui ignore
 * précisément les images non exploitables. Elle se limite à celles qui ne
 * s'afficheront pas.
 *
 * L'image à la une est traitée par `FeaturedImageRule` et n'est pas reprise
 * ici : un même défaut ne doit pas produire deux remarques.
 */
class BrokenImageRule implements AuditRule
{
    /**
     * Statuts d'analyse qui signent une image réellement cassée.
     *
     * `decode_failed` et `too_large` en sont volontairement exclus : l'URL
     * renvoie bien une image, que le navigateur sait afficher même si GD n'a
     * pas su la décoder ici.
     */
    protected const BROKEN_STATUSES = [
        ImageAnalysis::STATUS_UNREACHABLE,
        ImageAnalysis::STATUS_BLOCKED,
    ];

    public function __construct(
        protected ImageQualityAnalyzer $analyzer,
    ) {}

    public function key(): string
    {
        return 'broken_image';
    }

    public function label(): string
    {
        return 'Images cassées';
    }

    public function requiresNetwork(): bool
    {
        return true;
    }

    public function issueTypes(): array
    {
        return ['body_image_broken'];
    }

    public function evaluate(AuditContext $context): array
    {
        $issues = [];
        $maxImages = (int) config('articleguard.images.max_body_images', 6);
        $featured = (string) $context->article->featured_media_url;
        $seen = [];

        foreach (array_slice($context->html()->images(), 0, $maxImages) as $image) {
            $src = (string) $image['src'];

            if ($src === '' || $src === $featured || isset($seen[$src])) {
                continue;
            }

            $seen[$src] = true;

            // Le cache d'analyses évite de retélécharger une URL déjà vue par
            // une autre règle ou par un audit précédent.
            $analysis = $context->allowNetwork
                ? $this->analyzer->analyze($src)
                : $this->analyzer->cached($src);

            if ($analysis === null || ! $this->isBroken($analysis)) {
                continue;
            }

            $issues[] = Issue::error(
                'body_image_broken',
                'Image cassée dans le contenu',
                [
                    'target' => $src,
                    'src' => $src,
                    'alt' => $image['alt'] ?? null,
                    'status' => $analysis->status,
                    'error' => $analysis->error_code,
                    'scope' => 'content',
                ]
            );
        }

        return $issues;
    }

    protected function isBroken(ImageAnalysis $analysis): bool
    {
        if (in_array($analysis->status, self::BROKEN_STATUSES, true)) {
            return true;
        }

        // L'URL répond, mais ce qu'elle renvoie n'est pas une image : page
        // d'erreur HTML servie en 200, fichier tronqué, redirection perdue.
        return $analysis->status === ImageAnalysis::STATUS_UNSUPPORTED
            && $analysis->error_code === 'not_an_image';
    }
}
