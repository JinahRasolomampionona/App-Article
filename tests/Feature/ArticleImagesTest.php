<?php

namespace Tests\Feature;

use App\Models\ArticleAuditIssue;
use App\Models\ImageAnalysis;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Colonne « Images » du tableau des articles et son modal : toutes les images
 * de l'article (à la une + contenu), affichées, avec leur verdict de qualité.
 */
class ArticleImagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->site = WordpressSite::factory()->for($this->admin)->create(['url' => 'https://bijouteries.top']);
    }

    public function test_article_avec_seulement_une_image_a_la_une(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'title' => 'Est-ce qu’un bijoutier peut re-graver une médaille ?',
            'content' => '<p>Pas d’image ici.</p>',
            'featured_media_id' => 12,
            'featured_media_url' => 'https://bijouteries.top/wp-content/uploads/medaille.jpg',
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('articles.index', ['site' => $this->site->id, 'partial' => 1]))
            ->assertOk()
            ->assertSee('data-images-url', false)
            ->assertSee('medaille.jpg', false);

        $response = $this->actingAs($this->admin)->getJson(route('articles.images', $article))->assertOk();

        $response->assertJsonPath('count', 1);
        $this->assertStringContainsString('<img src="https://bijouteries.top/wp-content/uploads/medaille.jpg"', $response->json('html'));
        $this->assertStringContainsString('Image à la une', $response->json('html'));
        $this->assertStringContainsString('Non analysée', $response->json('html'));
    }

    public function test_toutes_les_images_du_contenu_sont_listees_avec_leur_verdict(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'content' => '<p><img src="https://bijouteries.top/a.jpg" alt="Bague"></p>'
                .'<figure><img src="/wp-content/uploads/b.jpg"></figure>'
                .'<img src="https://bijouteries.top/c.jpg">'
                .'<img src="javascript:alert(1)">',
            'featured_media_id' => 0,
            'featured_media_url' => null,
        ]);

        $this->analysis('https://bijouteries.top/a.jpg', ['width' => 1200, 'height' => 800, 'sharpness' => 40]);
        $this->analysis('/wp-content/uploads/b.jpg', ['width' => 300, 'height' => 200, 'sharpness' => 500]);
        $this->analysis('https://bijouteries.top/c.jpg', ['width' => 1200, 'height' => 800, 'sharpness' => 500]);

        ArticleAuditIssue::create([
            'wordpress_article_id' => $article->id,
            'rule_type' => 'image_possibly_incoherent',
            'severity' => 'info',
            'message' => 'Image potentiellement incohérente',
            'metadata' => ['src' => 'https://bijouteries.top/c.jpg', 'target' => 'https://bijouteries.top/c.jpg'],
            'detected_at' => now(),
        ]);

        $html = $this->actingAs($this->admin)->getJson(route('articles.images', $article))
            ->assertOk()
            ->assertJsonPath('count', 4)
            ->json('html');

        $this->assertStringContainsString('src="https://bijouteries.top/a.jpg"', $html);
        // URL relative résolue contre le domaine du site.
        $this->assertStringContainsString('src="https://bijouteries.top/wp-content/uploads/b.jpg"', $html);
        $this->assertStringContainsString('Potentiellement floue', $html);
        $this->assertStringContainsString('Faible résolution', $html);
        $this->assertStringContainsString('Potentiellement incohérente', $html);
        // Une URL non http(s) n'est jamais injectée dans la page.
        $this->assertDoesNotMatchRegularExpression('/(src|href)="javascript:/i', $html);
        $this->assertStringContainsString('Image non affichable', $html);
    }

    public function test_image_de_bonne_qualite(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'content' => '<img src="https://bijouteries.top/ok.jpg">',
            'featured_media_id' => 0,
            'featured_media_url' => null,
        ]);
        $this->analysis('https://bijouteries.top/ok.jpg', ['width' => 1600, 'height' => 900, 'sharpness' => 800]);

        $this->assertStringContainsString('Bonne qualité', $this->actingAs($this->admin)->getJson(route('articles.images', $article))->json('html'));
    }

    public function test_article_sans_image(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'content' => '<p>Texte</p>',
            'featured_media_id' => 0,
            'featured_media_url' => null,
        ]);

        $this->actingAs($this->admin)->getJson(route('articles.images', $article))->assertOk()->assertJsonPath('count', 0);
    }

    public function test_un_agent_non_assigne_au_site_ne_voit_pas_les_images(): void
    {
        $agent = User::factory()->create();
        $article = WordpressArticle::factory()->for($this->site, 'site')->create();

        $this->actingAs($agent)->getJson(route('articles.images', $article))->assertForbidden();

        $this->assignSite($agent, $this->site);
        $this->actingAs($agent)->getJson(route('articles.images', $article))->assertOk();
    }

    protected function analysis(string $url, array $attributes): ImageAnalysis
    {
        return ImageAnalysis::create($attributes + [
            'url_hash' => ImageAnalysis::hashFor($url),
            'url' => $url,
            'status' => ImageAnalysis::STATUS_OK,
            'analyzed_at' => now(),
        ]);
    }
}
