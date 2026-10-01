<?php

namespace Tests\Unit;

use App\Services\Audit\Relevance\ClaudeVisionImageRelevanceAnalyzer;
use App\Services\Audit\Relevance\RelevanceResult;
use App\Support\UrlGuard;
use Tests\TestCase;

/**
 * Cohérence image / article par vision : le verdict du modèle (trois critères)
 * est converti en résultat d'audit, sans appel réseau dans les tests.
 */
class VisionRelevanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['articleguard.relevance.vision.api_key' => 'test-key']);
    }

    public function test_un_critere_en_echec_rend_l_image_potentiellement_incoherente(): void
    {
        $analyzer = $this->analyzer([
            'title_match' => ['verdict' => 'pass', 'explanation' => 'Une bague est visible.'],
            'context_match' => ['verdict' => 'fail', 'explanation' => 'L’article parle d’une bague en or, l’image montre de l’argent.'],
            'precision' => ['verdict' => 'pass', 'explanation' => 'Le bijou est net.'],
            'summary' => 'Bague en argent alors que l’article traite de l’or.',
        ]);

        $result = $analyzer->analyze(['url' => 'https://example.com/bague.jpg'], ['title' => 'Bague en or']);

        $this->assertSame(RelevanceResult::POSSIBLY_INCOHERENT, $result->verdict);
        $this->assertSame(0.0, $result->score);
        $this->assertSame('Bague en argent alors que l’article traite de l’or.', $result->reason);
        $this->assertSame('fail', $result->details['criteria']['context']['verdict']);
        $this->assertSame('vision', $result->details['source']);
    }

    public function test_une_image_conforme_aux_trois_criteres_est_coherente(): void
    {
        $analyzer = $this->analyzer([
            'title_match' => ['verdict' => 'pass', 'explanation' => 'OK'],
            'context_match' => ['verdict' => 'pass', 'explanation' => 'OK'],
            'precision' => ['verdict' => 'uncertain', 'explanation' => 'Assez générique.'],
            'summary' => '',
        ]);

        $result = $analyzer->analyze(['url' => 'https://example.com/a.jpg'], ['title' => 'Colliers']);

        $this->assertSame(RelevanceResult::RELEVANT, $result->verdict);
        $this->assertSame(0.5, $result->score);
    }

    public function test_sans_critere_tranche_le_verdict_est_inconnu(): void
    {
        $analyzer = $this->analyzer([
            'title_match' => ['verdict' => 'uncertain', 'explanation' => ''],
            'context_match' => ['verdict' => 'uncertain', 'explanation' => ''],
            'precision' => ['verdict' => 'uncertain', 'explanation' => ''],
            'summary' => 'Image illisible.',
        ]);

        $result = $analyzer->analyze(['url' => 'https://example.com/a.jpg'], ['title' => 'Colliers']);

        $this->assertSame(RelevanceResult::UNKNOWN, $result->verdict);
    }

    public function test_le_verdict_est_mis_en_cache_par_image_et_par_titre(): void
    {
        $analyzer = $this->analyzer([
            'title_match' => ['verdict' => 'pass', 'explanation' => ''],
            'context_match' => ['verdict' => 'pass', 'explanation' => ''],
            'precision' => ['verdict' => 'pass', 'explanation' => ''],
            'summary' => '',
        ]);

        $analyzer->analyze(['url' => 'https://example.com/a.jpg'], ['title' => 'Colliers']);
        $analyzer->analyze(['url' => 'https://example.com/a.jpg'], ['title' => 'Colliers']);
        $this->assertSame(1, $analyzer->calls);

        $analyzer->analyze(['url' => 'https://example.com/a.jpg'], ['title' => 'Bracelets']);
        $this->assertSame(2, $analyzer->calls);
    }

    public function test_sans_cle_api_l_analyse_est_indisponible(): void
    {
        config(['articleguard.relevance.vision.api_key' => null]);

        $this->assertFalse(app(ClaudeVisionImageRelevanceAnalyzer::class)->isAvailable());
    }

    /**
     * @param  array<string, mixed>  $verdict
     */
    protected function analyzer(array $verdict): ClaudeVisionImageRelevanceAnalyzer
    {
        return new class(app(UrlGuard::class), $verdict) extends ClaudeVisionImageRelevanceAnalyzer
        {
            public int $calls = 0;

            public function __construct(UrlGuard $guard, protected array $verdict)
            {
                parent::__construct($guard);
            }

            protected function imageSource(string $url): ?array
            {
                return ['type' => 'url', 'url' => $url];
            }

            protected function ask(array $source, array $image, array $article): ?array
            {
                $this->calls++;

                return $this->verdict;
            }
        };
    }
}
