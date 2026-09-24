<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Issue;
use App\Services\Audit\Relevance\ImageRelevanceAnalyzerInterface;
use App\Services\Audit\Relevance\RelevanceResult;
use App\Services\Audit\Rules\Concerns\SelectsContentImages;

/**
 * Détection 4 — image potentiellement incohérente avec le sujet de l'article.
 *
 * Volontairement prudente :
 *  - elle ne prétend jamais qu'une image est « générée par IA » — ce n'est pas
 *    déterminable de façon fiable à partir d'une URL ;
 *  - elle ne signale que lorsqu'un fournisseur est disponible et que le score
 *    passe sous le seuil configuré ;
 *  - un verdict `unknown` ne produit aucune remarque ;
 *  - seules les images du contenu sont jugées, la section hero est écartée.
 */
class ImageRelevanceRule implements AuditRule
{
    use SelectsContentImages;

    public function __construct(
        protected ImageRelevanceAnalyzerInterface $analyzer,
    ) {}

    public function key(): string
    {
        return 'image_relevance';
    }

    public function label(): string
    {
        return 'Cohérence des images';
    }

    public function requiresNetwork(): bool
    {
        return true;
    }

    public function issueTypes(): array
    {
        return ['image_possibly_incoherent'];
    }

    public function evaluate(AuditContext $context): array
    {
        if (! $this->analyzer->isAvailable()) {
            return [];
        }

        $article = $context->article;
        $articlePayload = [
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'text' => $context->html()->plainText(),
        ];

        $issues = [];
        $candidates = array_map(fn (array $image) => $image + ['url' => $image['src']], $this->analyzableImages($context));

        foreach ($candidates as $candidate) {
            $result = $this->analyzer->analyze($candidate, $articlePayload);

            if (! $result->isPossiblyIncoherent()) {
                continue;
            }

            if ($result->score > (float) $context->settings->threshold('relevance', 0.35)) {
                continue;
            }

            $issues[] = Issue::info(
                'image_possibly_incoherent',
                'Image potentiellement incohérente',
                [
                    'target' => $candidate['target'],
                    'src' => $candidate['url'],
                    'alt' => $candidate['alt'],
                    'scope' => $candidate['scope'],
                    'score' => $result->score,
                    'reason' => $result->reason,
                    'image_terms' => $result->details['image_terms'] ?? [],
                    'matched_terms' => $result->details['matched_terms'] ?? [],
                    'verdict' => RelevanceResult::POSSIBLY_INCOHERENT,
                ]
            );
        }

        return $issues;
    }
}
