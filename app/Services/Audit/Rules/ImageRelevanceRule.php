<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Issue;
use App\Services\Audit\Relevance\ImageRelevanceAnalyzerInterface;
use App\Services\Audit\Relevance\RelevanceResult;

/**
 * Détection 4 — image potentiellement incohérente avec le sujet de l'article.
 *
 * Volontairement prudente :
 *  - elle ne prétend jamais qu'une image est « générée par IA » — ce n'est pas
 *    déterminable de façon fiable à partir d'une URL ;
 *  - elle ne signale que lorsqu'un fournisseur est disponible et que le score
 *    passe sous le seuil configuré ;
 *  - un verdict `unknown` ne produit aucune remarque.
 */
class ImageRelevanceRule implements AuditRule
{
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
        $candidates = [];

        if (filled($article->featured_media_url)) {
            $candidates[] = [
                'url' => (string) $article->featured_media_url,
                'alt' => (string) $article->featured_media_alt,
                'target' => 'featured_image',
                'scope' => 'featured',
            ];
        }

        foreach (array_slice($context->html()->images(), 0, (int) config('articleguard.images.max_body_images', 6)) as $image) {
            $candidates[] = [
                'url' => $image['src'],
                'alt' => $image['alt'],
                'title' => $image['title'],
                'caption' => $image['caption'],
                'target' => $image['src'],
                'scope' => 'content',
            ];
        }

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
                    'scope' => $candidate['scope'],
                    'score' => $result->score,
                    'reason' => $result->reason,
                    'verdict' => RelevanceResult::POSSIBLY_INCOHERENT,
                ]
            );
        }

        return $issues;
    }
}
