<?php

namespace Tests\Feature;

use App\Jobs\AuditArticleJob;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressCategory;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->site = WordpressSite::factory()->for($this->user)->create(['url' => 'https://example.com']);
    }

    /* --- Filtres et recherche ------------------------------------------------ */

    public function test_le_filtre_multi_categories_utilise_une_logique_ou_par_defaut(): void
    {
        [$bagues, $colliers] = $this->categories(['Bagues', 'Colliers']);

        $a = $this->article('Guide des bagues');
        $a->categories()->attach($bagues);

        $b = $this->article('Choisir un collier');
        $b->categories()->attach($colliers);

        $c = $this->article('Entretien des bracelets');

        $response = $this->actingAs($this->user)->getJson(route('articles.index', [
            'categories' => [$bagues->id, $colliers->id],
            'partial' => 1,
        ]));

        $response->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertStringContainsString($a->title, $response->json('html'));
        $this->assertStringContainsString($b->title, $response->json('html'));
        $this->assertStringNotContainsString($c->title, $response->json('html'));
    }

    /**
     * Les filtres du tableau sont écrits dans l'URL. Après un changement de
     * site, une URL conservée porterait les catégories de l'ancien site :
     * appliquées telles quelles, elles videraient le tableau d'un site qui
     * contient pourtant des articles.
     */
    public function test_les_categories_d_un_autre_site_sont_ignorees(): void
    {
        $autreSite = WordpressSite::factory()->for($this->user)->create(['url' => 'https://autre.example.com']);
        $categorieEtrangere = WordpressCategory::factory()->for($autreSite, 'site')->create(['name' => 'Bagues']);

        $article = $this->article('Article du site courant');

        $response = $this->actingAs($this->user)->getJson(route('articles.index', [
            'site' => $this->site->id,
            'categories' => [$categorieEtrangere->id],
            'partial' => 1,
        ]));

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertStringContainsString($article->title, $response->json('html'));
    }

    public function test_un_site_sans_article_synchronise_propose_la_synchronisation(): void
    {
        $response = $this->actingAs($this->user)->getJson(route('articles.index', [
            'site' => $this->site->id,
            'partial' => 1,
        ]));

        $response->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertStringContainsString('Aucun article synchronisé pour ce site', $response->json('html'));
    }

    public function test_une_recherche_sans_resultat_invite_a_ajuster_les_filtres(): void
    {
        $this->article('Guide des bagues');

        $response = $this->actingAs($this->user)->getJson(route('articles.index', [
            'site' => $this->site->id,
            'search' => 'introuvable-xyz',
            'partial' => 1,
        ]));

        $response->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertStringContainsString('Aucun article ne correspond', $response->json('html'));
    }

    public function test_le_mode_toutes_les_categories_restreint_les_resultats(): void
    {
        [$bagues, $colliers] = $this->categories(['Bagues', 'Colliers']);

        $deux = $this->article('Bagues et colliers');
        $deux->categories()->attach([$bagues->id, $colliers->id]);

        $une = $this->article('Bagues seulement');
        $une->categories()->attach($bagues);

        $response = $this->actingAs($this->user)->getJson(route('articles.index', [
            'categories' => [$bagues->id, $colliers->id],
            'mode' => 'all',
            'partial' => 1,
        ]));

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertStringContainsString($deux->title, $response->json('html'));
    }

    public function test_la_recherche_porte_sur_le_titre_le_slug_et_l_identifiant_wordpress(): void
    {
        $this->article('Guide des bagues', ['slug' => 'guide-bagues', 'wp_id' => 4242]);
        $this->article('Choisir un collier', ['slug' => 'choisir-collier', 'wp_id' => 7]);

        foreach (['bagues', 'guide-bagues', '4242'] as $term) {
            $response = $this->actingAs($this->user)
                ->getJson(route('articles.index', ['search' => $term, 'partial' => 1]));

            $response->assertOk()->assertJsonPath('meta.total', 1);
        }
    }

    public function test_le_filtre_de_statut_isole_les_articles_a_corriger(): void
    {
        $this->article('À corriger', ['audit_status' => WordpressArticle::AUDIT_NEEDS_FIX, 'issues_count' => 2]);
        $this->article('Conforme', ['audit_status' => WordpressArticle::AUDIT_OK]);

        $this->actingAs($this->user)
            ->getJson(route('articles.index', ['status' => 'needs_fix', 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_la_pagination_fonctionne_avec_les_filtres(): void
    {
        WordpressArticle::factory()->count(25)->for($this->site, 'site')->create();

        $page1 = $this->actingAs($this->user)
            ->getJson(route('articles.index', ['per_page' => 10, 'partial' => 1]));

        $page1->assertOk()
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.current_page', 1);

        $this->actingAs($this->user)
            ->getJson(route('articles.index', ['per_page' => 10, 'page' => 3, 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.from', 21);
    }

    /* --- Édition ------------------------------------------------------------- */

    public function test_la_mise_a_jour_envoie_les_champs_modifies_a_wordpress_puis_relance_l_audit(): void
    {
        Queue::fake();

        $article = $this->article('Ancien titre', [
            'wp_id' => 100,
            'content' => '<p>Ancien contenu.</p>',
            'featured_media_id' => 0,
            'featured_media_url' => null,
        ]);

        Http::fake([
            '*/wp/v2/posts/100*' => Http::response([
                'id' => 100,
                'slug' => 'ancien-titre',
                'title' => ['raw' => 'Nouveau titre'],
                'content' => ['raw' => '<h1>Unique</h1><p>Nouveau.</p><img src="https://example.com/i.jpg" alt="Bague">'],
                'featured_media' => 0,
                'categories' => [],
                'status' => 'publish',
            ]),
        ]);

        $this->lockFor($article, $this->user);

        $response = $this->actingAs($this->user)->putJson(route('articles.update', $article), [
            'title' => 'Nouveau titre',
            'content' => '<h1>Unique</h1><p>Nouveau.</p><img src="https://example.com/i.jpg" alt="Bague">',
            // Slug inchangé : il ne doit pas apparaître dans le corps envoyé.
            'slug' => $article->slug,
            'featured_media_id' => 0,
            'categories' => [],
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        // Seuls le titre et le contenu ont changé.
        $this->assertEqualsCanonicalizing(['title', 'content'], $response->json('changed'));
        $this->assertSame('Nouveau titre', $article->fresh()->title);

        // Un audit a bien été relancé après la sauvegarde.
        $this->assertNotNull($article->fresh()->last_audited_at);
        Queue::assertPushed(AuditArticleJob::class);
    }

    public function test_une_erreur_wordpress_est_renvoyee_dans_un_message_comprehensible(): void
    {
        Queue::fake();

        $article = $this->article('Titre', ['wp_id' => 100]);

        Http::fake(['*/wp/v2/posts/100*' => Http::response(['message' => 'Sorry, you are not allowed'], 403)]);

        $this->lockFor($article, $this->user);

        $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), [
                'title' => 'Un nouveau titre',
                'content' => '<p>x</p>',
            ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Accès refusé par WordPress. Le compte utilisé n’a pas les droits nécessaires sur cet article.');
    }

    /**
     * Sur un site lent, WordPress termine souvent l'enregistrement après que
     * la connexion a expiré de notre côté : la relecture doit le constater
     * plutôt que d'annoncer un échec, et l'écriture ne doit pas être rejouée.
     */
    public function test_une_mise_a_jour_expiree_mais_enregistree_est_confirmee(): void
    {
        Queue::fake();

        $article = $this->article('Ancien titre', [
            'wp_id' => 100,
            'wordpress_modified_at' => now()->subDay(),
        ]);

        $timeout = Http::failedConnection('cURL error 28: Operation timed out after 90001 milliseconds with 0 bytes received');

        Http::fake(function ($request) use ($timeout) {
            if ($request->method() === 'POST') {
                return $timeout($request);
            }

            return Http::response([
                'id' => 100,
                'slug' => 'ancien-titre',
                'modified_gmt' => now()->toIso8601String(),
                'title' => ['raw' => 'Nouveau titre'],
                'content' => ['raw' => '<p>Nouveau.</p>'],
                'featured_media' => 0,
                'categories' => [],
                'status' => 'publish',
            ]);
        });

        $this->lockFor($article, $this->user);

        $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), [
                'title' => 'Nouveau titre',
                'content' => '<p>Nouveau.</p>',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('Nouveau titre', $article->fresh()->title);
        Http::assertSentCount(2);
    }

    public function test_une_mise_a_jour_expiree_non_confirmee_affiche_une_erreur(): void
    {
        Queue::fake();

        $article = $this->article('Ancien titre', ['wp_id' => 100]);

        $timeout = Http::failedConnection('cURL error 28: Operation timed out after 90001 milliseconds with 0 bytes received');

        Http::fake(function ($request) use ($timeout, $article) {
            if ($request->method() === 'POST') {
                return $timeout($request);
            }

            return Http::response([
                'id' => 100,
                'modified_gmt' => $article->wordpress_modified_at?->toIso8601String(),
                'title' => ['raw' => 'Ancien titre'],
                'content' => ['raw' => (string) $article->content],
                'featured_media' => 0,
                'categories' => [],
                'status' => 'publish',
            ]);
        });

        $this->lockFor($article, $this->user);

        $response = $this->actingAs($this->user)->putJson(route('articles.update', $article), [
            'title' => 'Nouveau titre',
        ]);

        $response->assertStatus(502)->assertJsonPath('ok', false);
        $this->assertStringContainsString('n’a pas pu être confirmée', $response->json('message'));
        $this->assertSame('Ancien titre', $article->fresh()->title);
    }

    public function test_une_categorie_d_un_autre_site_est_refusee(): void
    {
        $article = $this->article('Titre');

        $autreSite = WordpressSite::factory()->for($this->user)->create(['url' => 'https://autre.example.com']);
        $categorieEtrangere = WordpressCategory::factory()->for($autreSite, 'site')->create();

        $this->lockFor($article, $this->user);

        $this->actingAs($this->user)
            ->putJson(route('articles.update', $article), [
                'title' => 'Titre',
                'content' => '<p>x</p>',
                'categories' => [$categorieEtrangere->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('categories.0');
    }

    public function test_l_audit_manuel_renvoie_la_ligne_mise_a_jour(): void
    {
        $article = $this->article(str_repeat('Titre beaucoup trop long ', 4), [
            'featured_media_id' => 0,
            'featured_media_url' => null,
            'content' => '<p>Sans image.</p>',
        ]);

        $response = $this->actingAs($this->user)->postJson(route('articles.audit', $article));

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertGreaterThan(0, $response->json('audit.issues_count'));
        $this->assertSame('À corriger', $response->json('audit.status_label'));
        $this->assertStringContainsString('À corriger', $response->json('row'));
    }

    public function test_l_audit_en_masse_programme_un_job_par_article(): void
    {
        Queue::fake();

        $articles = WordpressArticle::factory()->count(3)->for($this->site, 'site')->create();

        $this->actingAs($this->user)
            ->postJson(route('articles.bulk-audit'), ['ids' => $articles->pluck('id')->all()])
            ->assertOk()
            ->assertJsonPath('queued', 3);

        Queue::assertPushed(AuditArticleJob::class, 3);
    }

    /* --- Autorisations -------------------------------------------------------- */

    public function test_un_agent_connecte_au_meme_site_consulte_sans_modifier(): void
    {
        $agent = User::factory()->create();
        $this->connectSite($agent, $this->site);
        $article = $this->article('Partagé');

        // Même site, articles partagés : consultation ouverte…
        $this->actingAs($agent)->get(route('articles.show', $article))->assertOk();
        $this->actingAs($agent)->get(route('articles.edit', $article))
            ->assertOk()
            ->assertSee('data-readonly="1"', false)
            ->assertSee('Prendre l’article');

        // … mais aucune écriture sans avoir pris l'article.
        $this->actingAs($agent)
            ->putJson(route('articles.update', $article), ['title' => 'Piraté'])
            ->assertStatus(409);

        $this->assertSame('Partagé', $article->fresh()->title);
    }

    public function test_un_agent_ne_voit_pas_les_sites_qu_il_n_a_pas_connectes(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $article = $this->article('Privé');

        $this->actingAs($agent)->get(route('sites.index'))->assertOk()->assertDontSee($this->site->url);
        $this->actingAs($agent)->get(route('articles.show', $article))->assertForbidden();
        $this->actingAs($agent)->get(route('articles.edit', $article))->assertForbidden();
        $this->actingAs($agent)->postJson(route('articles.take', $article))->assertForbidden();
        $this->actingAs($agent)->get(route('sites.edit', $this->site))->assertForbidden();
        $this->actingAs($agent)->put(route('sites.update', $this->site), [
            'name' => 'Piraté', 'url' => $this->site->url,
        ])->assertForbidden();
        $this->actingAs($agent)->postJson(route('sites.test', $this->site))->assertForbidden();
        $this->actingAs($agent)->postJson(route('sites.sync', $this->site))->assertForbidden();
        $this->actingAs($agent)->delete(route('sites.destroy', $this->site))->assertForbidden();

        $this->assertDatabaseHas('wordpress_sites', ['id' => $this->site->id]);
    }

    public function test_une_synchronisation_deja_en_cours_n_est_pas_relancee(): void
    {
        Queue::fake();

        $this->site->forceFill(['sync_status' => 'running'])->save();

        $this->actingAs($this->user)
            ->postJson(route('sites.sync', $this->site))
            ->assertOk()
            ->assertJson(['already_running' => true, 'queue_warning' => null]);

        Queue::assertNothingPushed();
        $this->assertSame('running', $this->site->fresh()->sync_status);

        $this->actingAs($this->user)
            ->getJson(route('sites.sync-status', $this->site))
            ->assertJson(['sync_status' => 'running', 'queue_stalled' => false]);
    }

    public function test_l_audit_en_masse_ignore_les_identifiants_inconnus(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson(route('articles.bulk-audit'), ['ids' => [999999]])
            ->assertOk()
            ->assertJsonPath('queued', 0);

        Queue::assertNothingPushed();
    }

    /* --- Helpers -------------------------------------------------------------- */

    protected function article(string $title, array $attributes = []): WordpressArticle
    {
        return WordpressArticle::factory()->for($this->site, 'site')->create(
            array_merge(['title' => $title], $attributes)
        );
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, WordpressCategory>
     */
    protected function categories(array $names): array
    {
        return array_map(
            fn (string $name) => WordpressCategory::factory()->for($this->site, 'site')->create(['name' => $name]),
            $names
        );
    }
}
