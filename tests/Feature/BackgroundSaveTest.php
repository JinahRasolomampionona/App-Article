<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\QueueHealth;
use App\Services\QueueWorkerLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * « Mettre à jour » en arrière-plan : la requête répond tout de suite, un
 * processus détaché envoie l'article à WordPress, l'éditeur suit l'avancement.
 */
class BackgroundSaveTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<int, string>> */
    public static array $launched = [];

    protected User $user;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        self::$launched = [];

        // File « réelle » (non synchrone) : condition de l'envoi en arrière-plan.
        config(['queue.default' => 'database', 'articleguard.saves.background' => true]);
        Queue::fake();

        // Le processus détaché n'est pas lancé : on note seulement la commande.
        $this->app->bind(QueueWorkerLauncher::class, fn ($app) => new class($app->make(QueueHealth::class)) extends QueueWorkerLauncher
        {
            public function runInBackground(array $arguments): void
            {
                BackgroundSaveTest::$launched[] = $arguments;
            }

            public function ensureRunning(): bool
            {
                return false;
            }
        });

        $this->user = User::factory()->admin()->create();
        $this->site = WordpressSite::factory()->for($this->user)->create(['url' => 'https://example.com']);
    }

    public function test_la_mise_a_jour_repond_aussitot_puis_l_enregistrement_est_suivi_jusqu_a_la_fin(): void
    {
        $article = $this->editableArticle();
        $this->fakeWordPress(['id' => 100, 'title' => ['raw' => 'Nouveau titre'], 'content' => ['raw' => '<p>Nouveau.</p>']]);

        $response = $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), ['title' => 'Nouveau titre', 'content' => '<p>Nouveau.</p>'])
            ->assertStatus(202)
            ->assertJsonPath('pending', true);

        // Rien n'est encore parti vers WordPress : seul le processus est lancé.
        Http::assertNothingSent();
        $this->assertCount(1, self::$launched);
        $this->assertSame('articleguard:save-article', self::$launched[0][0]);

        $statusUrl = $response->json('status_url');
        $this->actingAs($this->user)->getJson($statusUrl)->assertOk()->assertJsonPath('status', 'pending');

        // Le processus détaché s'exécute.
        Artisan::call('articleguard:save-article', ['token' => self::$launched[0][1]]);

        $this->actingAs($this->user)->getJson($statusUrl)
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('message', 'Article mis à jour sur WordPress.')
            ->assertJsonStructure(['audit' => ['status', 'issues', 'panel']]);

        $this->assertSame('Nouveau titre', $article->fresh()->title);
        $this->assertNotNull($article->fresh()->last_audited_at);
    }

    public function test_le_cache_de_l_article_est_vide_apres_la_mise_a_jour(): void
    {
        $article = $this->editableArticle();

        Http::fake([
            '*/articleguard/v1/purge/100' => Http::response(['id' => 100, 'purged' => ['WordPress', 'WP Rocket']]),
            '*/wp/v2/posts/100*' => Http::response([
                'id' => 100, 'title' => ['raw' => 'Nouveau titre'], 'content' => ['raw' => '<p>x</p>'],
                'slug' => 'titre', 'featured_media' => 0, 'categories' => [], 'status' => 'publish',
            ]),
        ]);

        $statusUrl = $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), ['title' => 'Nouveau titre', 'content' => '<p>x</p>'])
            ->json('status_url');

        Artisan::call('articleguard:save-article', ['token' => self::$launched[0][1]]);

        $this->actingAs($this->user)->getJson($statusUrl)
            ->assertJsonPath('message', 'Article mis à jour sur WordPress. Cache vidé (WordPress, WP Rocket).');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp-json/articleguard/v1/purge/100'));
    }

    public function test_sans_l_extension_de_cache_l_enregistrement_reussit_quand_meme(): void
    {
        $article = $this->editableArticle();

        Http::fake([
            '*/articleguard/v1/*' => Http::response(['code' => 'rest_no_route', 'message' => 'Aucune route'], 404),
            '*/wp/v2/posts/100*' => Http::response([
                'id' => 100, 'title' => ['raw' => 'Nouveau titre'], 'content' => ['raw' => '<p>x</p>'],
                'slug' => 'titre', 'featured_media' => 0, 'categories' => [], 'status' => 'publish',
            ]),
        ]);

        $statusUrl = $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), ['title' => 'Nouveau titre', 'content' => '<p>x</p>'])
            ->json('status_url');

        Artisan::call('articleguard:save-article', ['token' => self::$launched[0][1]]);

        $this->actingAs($this->user)->getJson($statusUrl)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('message', 'Article mis à jour sur WordPress.');

        // Le bouton « Vider le cache » explique comment l'activer.
        $this->actingAs($this->user)->postJson(route('articles.purge-cache', $article))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_une_erreur_wordpress_est_rendue_par_le_suivi(): void
    {
        $article = $this->editableArticle();
        Http::fake(['*/wp/v2/posts/100*' => Http::response(['message' => 'Sorry, you are not allowed'], 403)]);

        $statusUrl = $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), ['title' => 'Un nouveau titre', 'content' => '<p>x</p>'])
            ->assertStatus(202)
            ->json('status_url');

        Artisan::call('articleguard:save-article', ['token' => self::$launched[0][1]]);

        $this->actingAs($this->user)->getJson($statusUrl)
            ->assertStatus(403)
            ->assertJsonPath('ok', false);

        $this->assertSame('Titre', $article->fresh()->title);
    }

    public function test_un_enregistrement_jamais_demarre_est_execute_par_le_suivi(): void
    {
        $article = $this->editableArticle();
        $this->fakeWordPress(['id' => 100, 'title' => ['raw' => 'Repris'], 'content' => ['raw' => '<p>x</p>']]);

        $statusUrl = $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), ['title' => 'Repris', 'content' => '<p>x</p>'])
            ->json('status_url');

        // Le processus détaché n'a jamais démarré.
        $this->travel(30)->seconds();

        $this->actingAs($this->user)->getJson($statusUrl)
            ->assertOk()
            ->assertJsonPath('status', 'done');

        $this->assertSame('Repris', $article->fresh()->title);

        // Exécuté une seule fois, même si le processus démarre finalement.
        Artisan::call('articleguard:save-article', ['token' => self::$launched[0][1]]);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/wp/v2/posts/')));
    }

    public function test_un_autre_compte_ne_peut_pas_suivre_l_enregistrement(): void
    {
        $article = $this->editableArticle();
        $this->fakeWordPress(['id' => 100]);

        $statusUrl = $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), ['title' => 'Nouveau titre', 'content' => '<p>x</p>'])
            ->json('status_url');

        $other = User::factory()->admin()->create();

        $this->actingAs($other)->getJson($statusUrl)->assertStatus(404);
    }

    public function test_sans_modification_la_reponse_est_immediate(): void
    {
        $article = $this->editableArticle();
        Http::fake();

        $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), ['title' => 'Titre', 'content' => $article->content])
            ->assertOk()
            ->assertJsonPath('changed', []);

        $this->assertSame([], self::$launched);
        Http::assertNothingSent();
    }

    protected function editableArticle(): WordpressArticle
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'wp_id' => 100,
            'title' => 'Titre',
            'content' => '<p>Ancien.</p>',
        ]);

        return $this->lockFor($article, $this->user);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    protected function fakeWordPress(array $post): void
    {
        Http::fake([
            '*/wp/v2/posts/100*' => Http::response($post + [
                'slug' => 'titre',
                'featured_media' => 0,
                'categories' => [],
                'status' => 'publish',
            ]),
        ]);
    }
}
