<?php

namespace Tests\Feature;

use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `wp:sync` : synchronisation et audit immédiats, sans worker.
 *
 * C'est le chemin de mise en route rapide : il doit produire des articles
 * audités en une seule commande.
 */
class SyncSiteCommandTest extends TestCase
{
    use RefreshDatabase;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = WordpressSite::factory()->create([
            'name' => 'Bijouteries',
            'url' => 'https://example.com',
            'sync_status' => 'queued',
        ]);

        Http::fake([
            'example.com/wp-json?*' => Http::response([
                'name' => 'Bijouteries',
                'namespaces' => ['wp/v2'],
                'authentication' => ['application-passwords' => []],
            ]),
            'example.com/wp-json/wp/v2/categories*' => Http::response([
                ['id' => 7, 'name' => 'Bagues', 'slug' => 'bagues', 'parent' => 0, 'count' => 2],
            ], 200, ['X-WP-Total' => 1, 'X-WP-TotalPages' => 1]),
            'example.com/wp-json/wp/v2/media*' => Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 0]),
            'example.com/wp-json/wp/v2/posts*' => Http::response([
                [
                    'id' => 101,
                    'slug' => 'un-titre-vraiment-beaucoup-trop-long-pour-tenir-dans-la-limite',
                    'link' => 'https://example.com/trop-long',
                    'title' => ['raw' => 'Un titre vraiment beaucoup trop long pour tenir dans la limite fixée'],
                    'content' => ['raw' => '<p>Texte sans image.</p>'],
                    'excerpt' => ['raw' => 'Extrait'],
                    'featured_media' => 0,
                    'categories' => [7],
                    'status' => 'publish',
                    'modified_gmt' => '2026-01-01T10:00:00',
                    'date_gmt' => '2026-01-01T09:00:00',
                ],
            ], 200, ['X-WP-Total' => 1, 'X-WP-TotalPages' => 1]),
        ]);
    }

    public function test_la_commande_synchronise_et_audite_en_une_seule_passe(): void
    {
        $this->artisan('wp:sync example.com --quick')
            ->assertSuccessful();

        $this->assertSame(1, $this->site->articles()->count());
        $this->assertSame(1, $this->site->categories()->count());

        $article = $this->site->articles()->first();

        // L'audit a bien tourné : titre trop long + image de contenu absente.
        $this->assertSame(WordpressArticle::AUDIT_NEEDS_FIX, $article->audit_status);
        $this->assertGreaterThan(0, $article->issues_count);

        // Le site n'est plus bloqué sur « queued ».
        $this->assertSame('idle', $this->site->fresh()->sync_status);
        $this->assertNotNull($this->site->fresh()->last_sync_at);
    }

    public function test_l_option_no_audit_synchronise_sans_auditer(): void
    {
        $this->artisan('wp:sync example.com --no-audit')
            ->assertSuccessful();

        $this->assertSame(
            WordpressArticle::AUDIT_PENDING,
            $this->site->articles()->first()->audit_status
        );
    }

    public function test_un_domaine_inconnu_echoue_proprement(): void
    {
        $this->artisan('wp:sync inconnu.test')
            ->expectsOutputToContain('Aucun site WordPress à synchroniser.')
            ->assertFailed();
    }
}
