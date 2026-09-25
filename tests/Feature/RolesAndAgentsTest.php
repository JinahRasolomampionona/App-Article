<?php

namespace Tests\Feature;

use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rôles Admin / Agent : pages réservées, gestion des comptes et
 * confidentialité des statistiques — tout contrôlé côté serveur.
 */
class RolesAndAgentsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $daniella;

    protected User $jinah;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $this->daniella = User::factory()->create(['name' => 'Daniella']);
        $this->jinah = User::factory()->create(['name' => 'Jinah']);
        $this->site = WordpressSite::factory()->for($this->admin)->create(['name' => 'bijouteries.top']);
    }

    /* --- Pages d'administration ------------------------------------------------ */

    public function test_un_agent_n_accede_a_aucune_page_admin(): void
    {
        $this->actingAs($this->jinah);

        $this->get(route('agents.index'))->assertForbidden();
        $this->get(route('agents.create'))->assertForbidden();
        $this->post(route('agents.store'), [])->assertForbidden();
        $this->get(route('agents.edit', $this->daniella))->assertForbidden();
        $this->put(route('agents.update', $this->jinah), ['name' => 'Jinah', 'email' => $this->jinah->email, 'role' => 'admin'])
            ->assertForbidden();
        $this->delete(route('agents.destroy', $this->daniella))->assertForbidden();
        $this->get(route('settings.edit'))->assertForbidden();

        $this->assertSame(User::ROLE_AGENT, $this->jinah->fresh()->role);
    }

    public function test_la_sidebar_d_un_agent_ne_propose_pas_l_administration(): void
    {
        $this->actingAs($this->jinah)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Mes statistiques')
            ->assertDontSee('data-label="Agents"', false)
            ->assertDontSee('data-label="Paramètres"', false);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertSee('data-label="Agents"', false)
            ->assertSee('data-label="Sites WordPress"', false);
    }

    public function test_un_agent_connecte_et_gere_son_propre_site(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 0]),
        ]);

        $this->actingAs($this->jinah)
            ->get(route('dashboard'))
            ->assertSee('data-label="Sites WordPress"', false);

        $this->actingAs($this->jinah)->get(route('sites.create'))->assertOk();

        $this->actingAs($this->jinah)->post(route('sites.store'), [
            'name' => 'Site de Jinah',
            'url' => 'https://93.184.216.34',
            'wp_username' => 'jinah',
            'application_password' => 'abcd efgh ijkl mnop qrst uvwx',
        ]);

        $site = WordpressSite::firstWhere('name', 'Site de Jinah');

        $this->assertNotNull($site);
        $this->assertSame($this->jinah->id, $site->user_id);

        $this->actingAs($this->jinah)->get(route('sites.edit', $site))->assertOk();
        $this->actingAs($this->daniella)->get(route('sites.edit', $site))->assertForbidden();
        // L'Admin suit le site (liste « Sites connectés par les agents »,
        // articles) mais ne touche pas aux identifiants de Jinah.
        $this->actingAs($this->admin)->get(route('sites.index'))->assertOk()
            ->assertSee('Sites connectés par les agents')
            ->assertSee('Site de Jinah');
        $this->actingAs($this->admin)->get(route('articles.index', ['site' => $site->id]))->assertOk();
        $this->actingAs($this->admin)->get(route('sites.edit', $site))->assertForbidden();

        $this->actingAs($this->jinah)->delete(route('sites.destroy', $site))->assertRedirect();
        $this->assertNull(WordpressSite::find($site->id));
    }

    public function test_un_agent_ne_voit_que_les_sites_qu_il_a_connectes(): void
    {
        WordpressArticle::factory()->for($this->site, 'site')->create(['title' => 'Guide des bagues']);

        // Jinah n'a pas connecté le site de l'Admin : il n'existe pas pour elle.
        $this->actingAs($this->jinah)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertDontSee('Guide des bagues');

        $this->connectSite($this->jinah, $this->site);

        $this->actingAs($this->jinah)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertSee('Guide des bagues');
    }

    /**
     * L'Admin et un agent connectent le même site, chacun avec ses
     * identifiants : un seul jeu d'articles, l'Admin voit le travail de
     * l'agent. Retirer sa connexion ne supprime pas le site des autres.
     */
    public function test_un_meme_site_connecte_par_deux_comptes_partage_ses_articles(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 0]),
        ]);

        $site = WordpressSite::factory()->for($this->admin)->create([
            'name' => 'bijouteries.top',
            'url' => 'https://93.184.216.34',
            'wp_username' => 'admin-wp',
        ]);
        $article = WordpressArticle::factory()->for($site, 'site')->create(['title' => 'Guide des bagues']);

        $this->actingAs($this->daniella)->post(route('sites.store'), [
            'name' => 'Mon bijouteries',
            'url' => 'https://93.184.216.34',
            'wp_username' => 'daniella-wp',
            'application_password' => 'abcd efgh ijkl mnop qrst uvwx',
        ])->assertRedirect();

        // Pas de second site : Daniella a rejoint celui de l'Admin.
        $this->assertSame(1, WordpressSite::where('url', 'https://93.184.216.34')->count());
        $this->assertSame(2, $site->connections()->count());

        // Chacun ses identifiants.
        $this->actingAs($this->daniella);
        $this->assertSame('daniella-wp', $site->fresh()->wp_username);
        $this->actingAs($this->admin);
        $this->assertSame('admin-wp', $site->fresh()->wp_username);

        // Daniella prend l'article ; l'Admin le voit « En cours par Daniella ».
        $this->actingAs($this->daniella)->postJson(route('articles.take', $article))->assertOk();

        $this->actingAs($this->admin)
            ->get(route('articles.index', ['site' => $site->id]))
            ->assertSee('En cours par Daniella');

        // Daniella se retire : le site et l'article restent pour l'Admin.
        $this->actingAs($this->daniella)->delete(route('sites.destroy', $site))->assertRedirect();

        $this->assertNotNull(WordpressSite::find($site->id));
        $this->assertNotNull($article->fresh());
        $this->assertSame(1, $site->connections()->count());
    }

    public function test_l_admin_n_attribue_un_article_qu_a_un_agent_connecte_au_site(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create();

        $this->actingAs($this->admin)
            ->postJson(route('articles.agent', $article), ['agent' => $this->jinah->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jinah n’a pas connecté ce site : impossible de lui attribuer cet article.');

        $this->connectSite($this->jinah, $this->site);

        $this->actingAs($this->admin)
            ->postJson(route('articles.agent', $article), ['agent' => $this->jinah->id])
            ->assertOk();
    }

    /* --- Comptes --------------------------------------------------------------- */

    public function test_l_admin_cree_un_compte_agent(): void
    {
        $this->actingAs($this->admin)
            ->post(route('agents.store'), [
                'name' => 'Koloina',
                'email' => 'koloina@example.com',
                'password' => 'motdepasse1',
                'password_confirmation' => 'motdepasse1',
            ])
            ->assertRedirect(route('agents.index'));

        $koloina = User::firstWhere('email', 'koloina@example.com');

        $this->assertNotNull($koloina);
        $this->assertTrue($koloina->isAgent());
        $this->assertTrue(password_verify('motdepasse1', $koloina->password));
    }

    public function test_la_creation_rattache_l_historique_saisi_sous_ce_nom(): void
    {
        $entry = ArticleStatusHistory::create([
            'user_id' => $this->admin->id,
            'site_name' => 'bijouteries.top',
            'status' => WordpressArticle::AUDIT_FIXED,
            'agent' => 'Miranto',
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->admin)->post(route('agents.store'), [
            'name' => 'Miranto',
            'email' => 'miranto@example.com',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ]);

        $this->assertSame(User::firstWhere('email', 'miranto@example.com')->id, $entry->fresh()->agent_user_id);
    }

    public function test_l_admin_ne_peut_ni_se_retrograder_ni_se_desactiver(): void
    {
        $this->actingAs($this->admin)
            ->put(route('agents.update', $this->admin), [
                'name' => 'Admin',
                'email' => $this->admin->email,
                'role' => 'agent',
                'is_active' => '0',
            ])
            ->assertRedirect();

        $this->admin->refresh();
        $this->assertTrue($this->admin->isAdmin());
        $this->assertTrue($this->admin->is_active);

        $this->actingAs($this->admin)->delete(route('agents.destroy', $this->admin))->assertForbidden();
    }

    public function test_desactiver_un_agent_bloque_sa_connexion_et_libere_ses_articles(): void
    {
        $article = $this->lockFor(WordpressArticle::factory()->for($this->site, 'site')->create(), $this->jinah);

        $this->actingAs($this->admin)->put(route('agents.update', $this->jinah), [
            'name' => 'Jinah',
            'email' => $this->jinah->email,
            'role' => 'agent',
            'is_active' => '0',
        ]);

        $this->assertFalse($this->jinah->fresh()->is_active);
        $this->assertNull($article->fresh()->assigned_to);

        // Session déjà ouverte : coupée à la requête suivante.
        $this->actingAs($this->jinah->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Nouvelle connexion : refusée.
        $this->post('/login', ['email' => $this->jinah->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_supprimer_un_compte_conserve_les_sites_et_l_historique(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        $site = WordpressSite::factory()->for($otherAdmin)->create(['url' => 'https://autre.test']);
        ArticleStatusHistory::create([
            'user_id' => $otherAdmin->id,
            'wordpress_site_id' => $site->id,
            'site_name' => 'autre.test',
            'status' => WordpressArticle::AUDIT_OK,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->admin)->delete(route('agents.destroy', $otherAdmin))->assertRedirect();

        $this->assertNull(User::find($otherAdmin->id));
        $this->assertSame($this->admin->id, $site->fresh()->user_id);
        $this->assertSame(1, ArticleStatusHistory::count());
    }

    public function test_le_role_ne_peut_pas_etre_injecte_a_l_inscription(): void
    {
        config(['articleguard.open_registration' => true]);

        $this->post('/register', [
            'name' => 'Pirate',
            'email' => 'pirate@example.com',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
            'role' => 'admin',
        ]);

        $this->assertTrue(User::firstWhere('email', 'pirate@example.com')->isAgent());
    }

    public function test_l_inscription_est_fermee_une_fois_l_espace_cree(): void
    {
        $this->get('/register')->assertRedirect(route('login'));

        $this->post('/register', [
            'name' => 'Inconnu',
            'email' => 'inconnu@example.com',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ])->assertForbidden();

        $this->assertNull(User::firstWhere('email', 'inconnu@example.com'));
    }

    /* --- Statistiques ---------------------------------------------------------- */

    public function test_un_agent_ne_voit_que_ses_propres_statistiques(): void
    {
        $this->correction($this->daniella, 'Article de Daniella');
        $this->correction($this->jinah, 'Article de Jinah');

        $response = $this->actingAs($this->jinah)
            // Paramètre forgé : il est ignoré pour un Agent.
            ->get(route('statistics.index', ['agent' => $this->daniella->id]))
            ->assertOk()
            ->assertSee('Mes statistiques')
            ->assertSee('Article de Jinah')
            ->assertDontSee('Article de Daniella')
            ->assertDontSee('Par agent');

        $this->assertSame(1, $response->viewData('me')['corrected']);
    }

    public function test_l_admin_voit_toutes_les_statistiques_et_filtre_par_agent(): void
    {
        $this->correction($this->daniella, 'Article de Daniella');
        $this->correction($this->jinah, 'Article de Jinah');
        $this->lockFor(WordpressArticle::factory()->for($this->site, 'site')->create(), $this->jinah);

        $this->actingAs($this->admin)
            ->get(route('statistics.index'))
            ->assertOk()
            ->assertSee('Par agent')
            ->assertSee('Article de Daniella')
            ->assertSee('Article de Jinah')
            ->assertSee('Articles en cours');

        $this->actingAs($this->admin)
            ->get(route('statistics.index', ['agent' => $this->daniella->id]))
            ->assertOk()
            ->assertSee('Corrections de Daniella')
            ->assertSee('Article de Daniella')
            ->assertDontSee('Article de Jinah');
    }

    public function test_un_agent_cree_apparait_aussitot_dans_les_statistiques(): void
    {
        // Nom saisi sans compte : ne doit pas apparaître dans « Par agent ».
        ArticleStatusHistory::create([
            'user_id' => $this->admin->id,
            'site_name' => 'bijouteries.top',
            'status' => WordpressArticle::AUDIT_FIXED,
            'agent' => 'Fantôme',
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->admin)->post(route('agents.store'), [
            'name' => 'Niriantsoa',
            'email' => 'niriantsoa@example.com',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ]);

        $response = $this->actingAs($this->admin)->get(route('statistics.index'))->assertOk();

        $labels = collect($response->viewData('agentRows'))->pluck('label')->all();

        $this->assertEqualsCanonicalizing(['Daniella', 'Jinah', 'Niriantsoa'], $labels);
        $response->assertDontSee('>Corrections<', false)->assertSee('Corrigés')->assertSee('En cours');
    }

    public function test_l_admin_filtre_les_statistiques_par_date(): void
    {
        $this->correction($this->daniella, 'Ancienne correction', now()->subMonths(2));
        $this->correction($this->daniella, 'Correction récente', now());

        $this->actingAs($this->admin)
            ->get(route('statistics.index', ['from' => now()->subWeek()->toDateString()]))
            ->assertOk()
            ->assertSee('Correction récente')
            ->assertDontSee('Ancienne correction');
    }

    protected function correction(User $agent, string $title, $at = null): ArticleStatusHistory
    {
        return ArticleStatusHistory::create([
            'user_id' => $this->admin->id,
            'wordpress_site_id' => $this->site->id,
            'site_name' => $this->site->name,
            'article_title' => $title,
            'status' => WordpressArticle::AUDIT_FIXED,
            'agent' => $agent->name,
            'agent_user_id' => $agent->id,
            'recorded_at' => $at ?? now(),
        ]);
    }
}
