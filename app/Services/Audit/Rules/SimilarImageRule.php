<?php

namespace App\Services\Audit\Rules;

use App\Models\ImageAnalysis;
use App\Services\Audit\AuditContext;
use App\Services\Audit\ImageQualityAnalyzer;
use App\Services\Audit\Issue;
use App\Services\Audit\Rules\Concerns\SelectsContentImages;

/**
 * Même photo publiée sous deux fichiers différents.
 *
 * Cas typique : la photo est téléversée deux fois dans WordPress
 * (`bague.jpg` puis `bague-1.jpg`), une fois comme image à la une, une fois
 * dans le contenu. Le thème affiche l'image à la une en tête d'article : le
 * lecteur voit la même photo deux fois. Les URL diffèrent, seule la
 * comparaison des images elles-mêmes (empreinte visuelle mesurée lors du
 * téléchargement) le révèle.
 *
 * Les copies d'un même fichier sont du ressort de `DuplicateImageRule`.
 */
class SimilarImageRule implements AuditRule
{
    use SelectsContentImages;

    public function __construct(
        protected ImageQualityAnalyzer $analyzer,
    ) {}

    public function key(): string
    {
        return 'similar_image';
    }

    public function label(): string
    {
        return 'Même photo en double';
    }

    public function requiresNetwork(): bool
    {
        return true;
    }

    public function issueTypes(): array
    {
        return ['similar_image'];
    }

    public function evaluate(AuditContext $context): array
    {
        $candidates = $this->candidates($context);

        if (count($candidates) < 2) {
            return [];
        }

        $fingerprints = [];

        foreach ($candidates as $i => $candidate) {
            $analysis = $context->imageAnalysis($this->analyzer, $candidate['src']);

            $fingerprints[$i] = $analysis?->status === ImageAnalysis::STATUS_OK && $analysis->fingerprint !== ''
                ? $analysis->fingerprint
                : null;
        }

        // Regroupement : chaque image rejoint le premier groupe dont elle est
        // visuellement identique.
        $groups = [];

        foreach ($candidates as $i => $candidate) {
            if ($fingerprints[$i] === null) {
                continue;
            }

            foreach ($groups as &$group) {
                if (ImageQualityAnalyzer::similar($fingerprints[$group[0]], $fingerprints[$i])) {
                    $group[] = $i;

                    continue 2;
                }
            }
            unset($group);

            $groups[] = [$i];
        }

        $issues = [];

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            $members = array_map(fn (int $i) => $candidates[$i], $group);
            $content = array_values(array_filter($members, fn (array $m) => $m['scope'] === 'content'));
            $withFeatured = count($content) < count($members);

            // L'image du contenu est celle à corriger : la remarque la désigne.
            $first = $content[0] ?? $members[0];

            $issues[] = Issue::warning(
                'similar_image',
                $withFeatured
                    ? 'Image en double : l’image à la une est répétée dans le contenu'
                    : 'Image en double : même photo sous '.count($members).' fichiers',
                [
                    'target' => $first['src'],
                    'src' => $first['src'],
                    'alt' => $first['alt'],
                    'scope' => 'content',
                    'includes_featured' => $withFeatured,
                    'positions' => array_map(fn (array $m) => $m['position'], $content),
                    'files' => array_map(fn (array $m) => basename((string) parse_url($m['src'], PHP_URL_PATH)), $members),
                ]
            );
        }

        return $issues;
    }

    /**
     * Image à la une (affichée en tête par le thème) puis une image par
     * fichier du contenu, hors blocs « hero ».
     *
     * @return array<int, array{src: string, alt: string, scope: string, position: ?int}>
     */
    protected function candidates(AuditContext $context): array
    {
        $candidates = [];
        $seen = [];
        $featured = (string) $context->article->featured_media_url;

        // L'image à la une n'entre pas dans `$seen` : sa reprise dans le
        // contenu, même sous le même fichier, est bien un doublon à l'écran.
        if ($featured !== '' && config('articleguard.images.featured_shown_by_theme', true)) {
            $candidates[] = ['src' => $featured, 'alt' => (string) $context->article->featured_media_alt, 'scope' => 'featured', 'position' => null];
        }

        $max = (int) config('articleguard.images.max_compared_images', 12);

        foreach ($context->html()->images() as $index => $image) {
            if (count($candidates) >= $max) {
                break;
            }

            $key = $this->mediaKey($image['src']);

            if ($image['in_hero'] || $key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $candidates[] = ['src' => $image['src'], 'alt' => $image['alt'], 'scope' => 'content', 'position' => $index + 1];
        }

        return $candidates;
    }
}
