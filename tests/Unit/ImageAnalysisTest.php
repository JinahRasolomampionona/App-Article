<?php

namespace Tests\Unit;

use App\Models\ImageAnalysis;
use App\Models\WordpressArticle;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditSettings;
use App\Services\Audit\ImageQualityAnalyzer;
use App\Services\Audit\Relevance\HeuristicImageRelevanceAnalyzer;
use App\Services\Audit\Relevance\NullImageRelevanceAnalyzer;
use App\Services\Audit\Relevance\RelevanceResult;
use App\Services\Audit\Rules\ImageBlurRule;
use App\Services\Audit\Rules\ImageRelevanceRule;
use App\Support\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImageAnalysisTest extends TestCase
{
    use RefreshDatabase;

    /* --- Score de netteté --------------------------------------------------- */

    public function test_une_image_nette_obtient_un_score_plus_eleve_qu_une_image_floue(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('L’extension GD est requise pour l’analyse de netteté.');
        }

        Http::fake([
            'https://example.com/nette.png' => Http::response($this->checkerboardPng(), 200, ['Content-Type' => 'image/png']),
            'https://example.com/floue.png' => Http::response($this->flatPng(), 200, ['Content-Type' => 'image/png']),
        ]);

        $analyzer = new ImageQualityAnalyzer(new UrlGuard);

        $sharp = $analyzer->analyze('https://example.com/nette.png');
        $blurry = $analyzer->analyze('https://example.com/floue.png');

        $this->assertSame(ImageAnalysis::STATUS_OK, $sharp->status);
        $this->assertSame(ImageAnalysis::STATUS_OK, $blurry->status);
        $this->assertGreaterThan($blurry->sharpness, $sharp->sharpness);
        $this->assertTrue($blurry->is_blurry);
        $this->assertFalse($sharp->is_blurry);
    }

    public function test_une_image_est_analysee_une_seule_fois(): void
    {
        Http::fake(['*' => Http::response($this->flatPng(), 200, ['Content-Type' => 'image/png'])]);

        $analyzer = new ImageQualityAnalyzer(new UrlGuard);

        $analyzer->analyze('https://example.com/image.png');
        $analyzer->analyze('https://example.com/image.png');

        // Le second appel est servi par le cache : une seule requête HTTP.
        Http::assertSentCount(1);
        $this->assertSame(1, ImageAnalysis::count());
    }

    public function test_une_image_injoignable_est_marquee_comme_telle(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $analysis = (new ImageQualityAnalyzer(new UrlGuard))->analyze('https://example.com/absente.jpg');

        $this->assertSame(ImageAnalysis::STATUS_UNREACHABLE, $analysis->status);
        $this->assertNull($analysis->sharpness);
    }

    public function test_une_url_interne_est_bloquee_sans_appel_reseau(): void
    {
        config(['articleguard.ssrf.allowlist' => [], 'articleguard.ssrf.allow_private' => false]);
        Http::fake();

        $analysis = (new ImageQualityAnalyzer(new UrlGuard))->analyze('http://169.254.169.254/latest/meta-data');

        $this->assertSame(ImageAnalysis::STATUS_BLOCKED, $analysis->status);
        Http::assertNothingSent();
    }

    /* --- Règle de flou ------------------------------------------------------ */

    public function test_la_regle_de_flou_signale_une_image_sous_le_seuil(): void
    {
        Http::fake(['*' => Http::response($this->flatPng(), 200, ['Content-Type' => 'image/png'])]);

        $article = new WordpressArticle([
            'title' => 'Bague',
            'content' => '<p>Texte.</p><img src="https://example.com/floue.png" alt="Bague">',
        ]);

        $issues = (new ImageBlurRule(new ImageQualityAnalyzer(new UrlGuard)))
            ->evaluate(new AuditContext($article, AuditSettings::defaults(), allowNetwork: true));

        $this->assertNotEmpty($issues);
        $this->assertSame('image_blurry', $issues[0]->type);
        $this->assertSame('Image potentiellement floue', $issues[0]->message);
        $this->assertSame('content', $issues[0]->metadata['scope']);
    }

    public function test_la_section_hero_est_exclue_de_l_analyse_de_flou(): void
    {
        Http::fake(['*' => Http::response($this->flatPng(), 200, ['Content-Type' => 'image/png'])]);

        $article = new WordpressArticle([
            'title' => 'Bague',
            // Image à la une (bandeau hero du thème), une autre taille de cette
            // même image dans le contenu, puis une image dans un bloc hero.
            'content' => '<p>Texte.</p>'
                .'<img src="https://example.com/uploads/bandeau-1024x768.png">'
                .'<div class="page-hero"><img src="https://example.com/uploads/fond.png"></div>'
                .'<div class="wp-block-cover"><img src="https://example.com/uploads/cover.png"></div>',
            'featured_media_id' => 3,
            'featured_media_url' => 'https://example.com/uploads/bandeau-scaled.png',
        ]);

        $issues = (new ImageBlurRule(new ImageQualityAnalyzer(new UrlGuard)))
            ->evaluate(new AuditContext($article, AuditSettings::defaults(), allowNetwork: true));

        $this->assertSame([], $issues);
        Http::assertNothingSent();
    }

    public function test_l_image_a_la_une_peut_etre_reintegree_par_configuration(): void
    {
        config(['articleguard.images.analyze_featured_image' => true]);
        Http::fake(['*' => Http::response($this->flatPng(), 200, ['Content-Type' => 'image/png'])]);

        $article = new WordpressArticle([
            'title' => 'Bague',
            'content' => '<p>Texte.</p>',
            'featured_media_id' => 3,
            'featured_media_url' => 'https://example.com/floue.png',
        ]);

        $issues = (new ImageBlurRule(new ImageQualityAnalyzer(new UrlGuard)))
            ->evaluate(new AuditContext($article, AuditSettings::defaults(), allowNetwork: true));

        $this->assertSame('featured', $issues[0]->metadata['scope']);
    }

    public function test_l_image_a_la_une_n_est_pas_jugee_incoherente(): void
    {
        $article = new WordpressArticle([
            'title' => 'Comment choisir une bague en diamant',
            'content' => '<p>Choisir une bague en diamant demande de la méthode.</p>',
            'featured_media_id' => 3,
            'featured_media_url' => 'https://example.com/moteur-voiture-garage.jpg',
        ]);

        $issues = (new ImageRelevanceRule(new HeuristicImageRelevanceAnalyzer))
            ->evaluate(new AuditContext($article, AuditSettings::defaults(), allowNetwork: true));

        $this->assertSame([], $issues);
    }

    public function test_la_regle_de_flou_ne_telecharge_rien_sans_acces_reseau(): void
    {
        Http::fake();

        $article = new WordpressArticle([
            'title' => 'Bague',
            'content' => '<p>Texte.</p>',
            'featured_media_url' => 'https://example.com/image.png',
        ]);

        $issues = (new ImageBlurRule(new ImageQualityAnalyzer(new UrlGuard)))
            ->evaluate(new AuditContext($article, AuditSettings::defaults(), allowNetwork: false));

        $this->assertSame([], $issues);
        Http::assertNothingSent();
    }

    /* --- Pertinence --------------------------------------------------------- */

    public function test_une_image_decrite_en_lien_avec_l_article_est_jugee_pertinente(): void
    {
        $result = (new HeuristicImageRelevanceAnalyzer)->analyze(
            ['url' => 'https://example.com/bague-diamant.jpg', 'alt' => 'Bague en diamant'],
            ['title' => 'Comment choisir une bague en diamant', 'text' => 'Les bagues en diamant...'],
        );

        $this->assertSame(RelevanceResult::RELEVANT, $result->verdict);
    }

    public function test_une_image_sans_rapport_est_signalee_comme_douteuse(): void
    {
        $result = (new HeuristicImageRelevanceAnalyzer)->analyze(
            ['url' => 'https://example.com/voiture-moteur-garage.jpg', 'alt' => 'Moteur de voiture au garage'],
            ['title' => 'Comment choisir une bague en diamant', 'text' => 'Les bagues en diamant se choisissent...'],
        );

        $this->assertSame(RelevanceResult::POSSIBLY_INCOHERENT, $result->verdict);
    }

    public function test_une_image_sans_description_reste_indeterminee(): void
    {
        $result = (new HeuristicImageRelevanceAnalyzer)->analyze(
            ['url' => 'https://example.com/IMG-1024x768.jpg', 'alt' => ''],
            ['title' => 'Comment choisir une bague', 'text' => 'Texte.'],
        );

        // Aucun élément exploitable : on ne signale rien plutôt que d'accuser à tort.
        $this->assertSame(RelevanceResult::UNKNOWN, $result->verdict);
    }

    /**
     * Les noms de fichiers automatiques restent indéterminés.
     *
     * « Image1.jpg » ou « DSC05678.png » ne décrivent rien : les compter comme
     * du vocabulaire descriptif revenait à déclarer incohérente toute image
     * d'un site qui ne renomme pas ses fichiers.
     *
     * @param  string  $url
     */
    #[DataProvider('meaninglessFilenames')]
    public function test_un_nom_de_fichier_automatique_reste_indetermine($url): void
    {
        $result = (new HeuristicImageRelevanceAnalyzer)->analyze(
            ['url' => $url, 'alt' => ''],
            ['title' => 'Comment choisir une bague', 'text' => 'Les bagues en or blanc.'],
        );

        $this->assertSame(RelevanceResult::UNKNOWN, $result->verdict);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function meaninglessFilenames(): array
    {
        return [
            ['https://example.com/wp-content/uploads/2026/09/Image1.jpg'],
            ['https://example.com/wp-content/uploads/2026/09/image-2.png'],
            ['https://example.com/IMG_20240115.jpg'],
            ['https://example.com/DSC05678.png'],
            ['https://example.com/capture-decran-2024.jpg'],
        ];
    }

    /**
     * Un `alt` descriptif reste exploité même si le nom de fichier ne dit rien.
     */
    public function test_un_alt_descriptif_supplee_un_nom_de_fichier_muet(): void
    {
        $result = (new HeuristicImageRelevanceAnalyzer)->analyze(
            ['url' => 'https://example.com/Image2.png', 'alt' => 'Bague en or blanc'],
            ['title' => 'Comment choisir une bague', 'text' => 'Les bagues en or blanc.'],
        );

        $this->assertSame(RelevanceResult::RELEVANT, $result->verdict);
    }

    public function test_une_image_qui_reprend_le_sujet_du_titre_est_coherente(): void
    {
        // Cas réel : un alt rédigé en phrase diluait les mots-clés, et les
        // accents (« achète », « découvrez ») étaient mal découpés.
        $result = (new HeuristicImageRelevanceAnalyzer)->analyze(
            [
                'url' => 'https://example.com/uploads/Qui-achete-les-alliances-1-1.jpg',
                'alt' => "découvrez qui est généralement responsable de l'achat des alliances dans une relation, "
                    .'les traditions et conseils pour choisir les bagues parfaites.',
            ],
            [
                'title' => 'Qui achète les alliances ?',
                'excerpt' => '',
                'text' => 'Traditionnellement, les alliances sont achetées par le futur marié.',
            ],
        );

        $this->assertSame(RelevanceResult::RELEVANT, $result->verdict);
        $this->assertContains('decouvrez', $result->details['image_terms']);
    }

    public function test_sans_fournisseur_aucune_remarque_de_pertinence_n_est_produite(): void
    {
        $article = new WordpressArticle([
            'title' => 'Bague',
            'content' => '<img src="https://example.com/voiture.jpg" alt="Voiture">',
            'featured_media_url' => null,
        ]);

        $issues = (new ImageRelevanceRule(new NullImageRelevanceAnalyzer))
            ->evaluate(new AuditContext($article, AuditSettings::defaults(), allowNetwork: true));

        $this->assertSame([], $issues);
    }

    public function test_la_remarque_de_pertinence_reste_prudente(): void
    {
        $article = new WordpressArticle([
            'title' => 'Comment choisir une bague en diamant',
            'content' => '<img src="https://example.com/moteur-voiture-garage.jpg" alt="Moteur de voiture">',
            'featured_media_url' => null,
        ]);

        $issues = (new ImageRelevanceRule(new HeuristicImageRelevanceAnalyzer))
            ->evaluate(new AuditContext($article, AuditSettings::defaults(), allowNetwork: true));

        $this->assertNotEmpty($issues);
        $this->assertSame('Image potentiellement incohérente', $issues[0]->message);
        // Les mots qui décrivent l'image sont conservés pour le détail de l'audit.
        $this->assertContains('moteur', $issues[0]->metadata['image_terms']);
        // Le message ne doit jamais affirmer qu'une image est générée par IA.
        $this->assertStringNotContainsStringIgnoringCase('IA', $issues[0]->message);
    }

    /* --- Fixtures d'images --------------------------------------------------- */

    /**
     * Damier 1 pixel : beaucoup de transitions, donc une variance élevée.
     */
    protected function checkerboardPng(): string
    {
        $image = imagecreatetruecolor(64, 64);
        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);

        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                imagesetpixel($image, $x, $y, ($x + $y) % 2 === 0 ? $black : $white);
            }
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * Aplat uni : aucune transition, donc une variance nulle.
     */
    protected function flatPng(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefill($image, 0, 0, imagecolorallocate($image, 128, 128, 128));

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
