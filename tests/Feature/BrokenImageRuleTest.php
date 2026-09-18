<?php

namespace Tests\Feature;

use App\Models\WordpressArticle;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditSettings;
use App\Services\Audit\ImageQualityAnalyzer;
use App\Services\Audit\Issue;
use App\Services\Audit\Rules\BrokenImageRule;
use App\Support\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Images du contenu qui ne s'affichent pas.
 *
 * Le défaut est invisible côté HTML : seule une requête réelle sur l'URL permet
 * de le constater.
 */
class BrokenImageRuleTest extends TestCase
{
    // Le résultat de chaque analyse est mémorisé en base.
    use RefreshDatabase;

    protected BrokenImageRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rule = new BrokenImageRule(new ImageQualityAnalyzer(new UrlGuard));
    }

    public function test_une_image_en_404_est_signalee(): void
    {
        Http::fake(['example.com/cassee.jpg' => Http::response('Not found', 404)]);

        $issues = $this->rule->evaluate($this->context(
            '<p><img src="https://example.com/cassee.jpg" alt="Bague"></p>'
        ));

        $this->assertSame(['body_image_broken'], $this->types($issues));
        $this->assertSame('Image cassée dans le contenu', $issues[0]->message);
        $this->assertSame('https://example.com/cassee.jpg', $issues[0]->metadata['src']);
        $this->assertSame('Bague', $issues[0]->metadata['alt']);
    }

    public function test_une_url_qui_ne_renvoie_pas_une_image_est_signalee(): void
    {
        // Page d'erreur HTML servie en 200 : le navigateur affichera une image
        // cassée malgré le succès HTTP.
        Http::fake(['example.com/page.jpg' => Http::response('<html>oups</html>', 200, ['Content-Type' => 'text/html'])]);

        $issues = $this->rule->evaluate($this->context(
            '<p><img src="https://example.com/page.jpg"></p>'
        ));

        $this->assertSame(['body_image_broken'], $this->types($issues));
    }

    public function test_une_image_valide_ne_produit_rien(): void
    {
        Http::fake(['example.com/ok.png' => Http::response($this->pngBytes(), 200, ['Content-Type' => 'image/png'])]);

        $issues = $this->rule->evaluate($this->context(
            '<p><img src="https://example.com/ok.png"></p>'
        ));

        $this->assertSame([], $issues);
    }

    public function test_un_contenu_sans_image_ne_produit_rien(): void
    {
        Http::fake();

        $this->assertSame([], $this->rule->evaluate($this->context('<p>Texte seul.</p>')));

        // Aucune image : aucune requête ne doit partir.
        Http::assertNothingSent();
    }

    /**
     * L'image à la une est couverte par `FeaturedImageRule` : la reprendre ici
     * produirait deux remarques pour un seul défaut.
     */
    public function test_l_image_a_la_une_n_est_pas_reprise(): void
    {
        Http::fake(['example.com/featured.jpg' => Http::response('Not found', 404)]);

        $issues = $this->rule->evaluate($this->context(
            '<p><img src="https://example.com/featured.jpg"></p>',
            'https://example.com/featured.jpg'
        ));

        $this->assertSame([], $issues);
    }

    public function test_sans_reseau_aucune_requete_n_est_declenchee(): void
    {
        Http::fake();

        $context = $this->context('<p><img src="https://example.com/inconnue.jpg"></p>', allowNetwork: false);

        $this->assertSame([], $this->rule->evaluate($context));
        Http::assertNothingSent();
    }

    protected function context(
        string $content,
        ?string $featuredUrl = 'https://example.com/featured-ok.jpg',
        bool $allowNetwork = true,
    ): AuditContext {
        $article = new WordpressArticle([
            'title' => 'Titre',
            'content' => $content,
            'featured_media_id' => 5,
            'featured_media_url' => $featuredUrl,
        ]);

        $settings = new AuditSettings(
            array_map('boolval', (array) config('articleguard.rules')),
            (array) config('articleguard.thresholds'),
        );

        return new AuditContext($article, $settings, $allowNetwork);
    }

    /** @param array<int, Issue> $issues */
    protected function types(array $issues): array
    {
        return array_map(fn (Issue $issue) => $issue->type, $issues);
    }

    /** PNG 2×2 valide, le plus petit contenu réellement décodable. */
    protected function pngBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
