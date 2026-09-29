<?php

namespace Tests\Feature;

use App\Models\ArticleStatusHistory;
use App\Models\SiteCredential;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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

    public function test_la_sidebar_d_un_agent_ne_propose_que_articles_et_sites(): void
    {
        $this->assignSite($this->jinah, $this->site);

        $this->actingAs($this->jinah)
            ->get(route('articles.index'))
            ->assertOk()
            ->assertSee('data-label="Articles"', false)
            ->assertSee('data-label="Sites WordPress"', false)
            ->assertDontSee('data-label="Dashboard"', false)
            ->assertDontSee('data-label="Audits"', false)
            ->assertDontSee('data-label="Statistiques"', false)
            ->assertDontSee('Mes statistiques')
            ->assertDontSee('data-label="Agents"', false)
            ->assertDontSee('data-label="Paramètres"', false);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertSee('data-label="Dashboard"', false)
            ->assertSee('data-label="Audits"', false)
            ->assertSee('data-label="Statistiques"', false)
            ->assertSee('data-label="Agents"', false)
            ->assertSee('data-label="Sites WordPress"', false);
    }

    /* --- Sites : connexion par l'Admin, assignation aux agents ----------------- */

    public function test_un_agent_ne_connecte_ni_ne_gere_de_site(): void
    {
        $this->assignSite($this->jinah, $this->site);

        $this->actingAs($this->jinah);

        $this->get(route('sites.create'))->assertForbidden();
        $this->post(route('sites.store'), ['name' => 'X', 'url' => 'https://93.184.216.34'])->assertForbidden();
        $this->get(route('sites.edit', $this->site))->assertForbidden();
        $this->put(route('sites.update', $this->site), ['name' => 'Piraté', 'url' => $this->site->url])->assertForbidden();
        $this->postJson(route('sites.test', $this->site))->assertForbidden();
        $this->delete(route('sites.destroy', $this->site))->assertForbidden();
        $this->post(route('sites.assign', $this->site), ['agents' => [$this->jinah->id]])->assertForbidden();
        $this->post(route('sites.assignment-status', [$this->site, $this->jinah]), ['status' => 'done'])->assertForbidden();

        $this->assertDatabaseHas('wordpress_sites', ['id' => $this->site->id, 'name' => $this->site->name]);
    }

    public function test_l_espace_sites_d_un_agent_n_affiche_que_synchroniser(): void
    {
        Queue::fake();
        $this->assignSite($this->jinah, $this->site);

        $this->actingAs($this->jinah)
            ->get(route('sites.index'))
            ->assertOk()
            ->assertSee($this->site->name)
            ->assertSee('En cours')
            ->assertSee('data-sync-url', false)
            ->assertDontSee('data-test-url', false)
            ->assertDontSee(route('sites.edit', $this->site), false)
            ->assertDontSee('aria-label="Supprimer', false)
            ->assertDontSee('Connecter un site');

        $this->actingAs($this->jinah)->postJson(route('sites.sync', $this->site))->assertOk();
    }

    public function test_un_agent_ne_voit_que_les_sites_qui_lui_sont_assignes(): void
    {
        WordpressArticle::factory()->for($this->site, 'site')->create(['title' => 'Guide des bagues']);

        // Pas encore assigné : le site n'existe pas pour Jinah.
        $this->actingAs($this->jinah)->get(route('sites.index'))->assertDontSee($this->site->name);
        $this->actingAs($this->jinah)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertDontSee('Guide des bagues');

        $this->assignSite($this->jinah, $this->site);

        $this->actingAs($this->jinah)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertSee('Guide des bagues');
    }

    /**
     * L'Admin assigne communitas.fr à Jinah, Koloina et Daniella : le site
     * apparaît « En cours » chez chacun, sans connexion à faire. « Terminer »
     * passe l'agent concerné à « Terminé » : le site disparaît de son espace
     * seulement.
     */
    public function test_l_admin_assigne_un_site_a_plusieurs_agents_puis_termine(): void
    {
        $koloina = User::factory()->create(['name' => 'Koloina']);
        $site = WordpressSite::factory()->for($this->admin)->create([
            'name' => 'communitas.fr',
            'url' => 'https://communitas.fr',
        ]);

        $this->actingAs($this->admin)
            ->post(route('sites.assign', $site), ['agents' => [$this->jinah->id, $koloina->id, $this->daniella->id]])
            ->assertRedirect(route('sites.index'));

        foreach ([$this->jinah, $koloina, $this->daniella] as $agent) {
            $this->actingAs($agent)
                ->get(route('sites.index'))
                ->assertOk()
                ->assertSee('communitas.fr')
                ->assertSee('En cours');
        }

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post(route('sites.assignment-status', [$site, $this->jinah]), ['status' => 'done'])
            ->assertOk();

        $this->assertSame('done', $site->assignmentOf($this->jinah)->status);
        $this->assertNotNull($site->assignmentOf($this->jinah)->completed_at);
        $this->assertSame('in_progress', $site->assignmentOf($koloina)->status);

        $this->actingAs($this->jinah)->get(route('sites.index'))->assertOk()->assertDontSee('communitas.fr');
        $this->actingAs($koloina)->get(route('sites.index'))->assertOk()->assertSee('communitas.fr');
    }

    public function test_terminer_retire_le_site_de_l_espace_de_l_agent_et_libere_ses_articles(): void
    {
        $this->assignSite($this->jinah, $this->site);
        $article = $this->lockFor(WordpressArticle::factory()->for($this->site, 'site')->create(), $this->jinah);

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post(route('sites.assignment-status', [$this->site, $this->jinah]), ['status' => 'done'])
            ->assertOk();

        $this->assertNull($article->fresh()->assigned_to);
        $this->actingAs($this->jinah)->get(route('articles.show', $article))->assertForbidden();
        $this->actingAs($this->jinah)->get(route('articles.edit', $article))->assertForbidden();
        $this->actingAs($this->jinah)->get(route('sites.index'))->assertDontSee($this->site->name);

        // L'Admin voit toujours Jinah, « Terminé », avec de quoi le réassigner.
        $this->actingAs($this->admin)->get(route('sites.index'))->assertSeeInOrder(['Jinah', 'Terminé', 'Réassigner']);
    }

    public function test_l_admin_peut_reassigner_un_site_termine(): void
    {
        $this->assignSite($this->jinah, $this->site);
        $this->site->assignmentOf($this->jinah)->forceFill(['status' => 'done', 'completed_at' => now()])->save();

        // En le cochant de nouveau dans la fenêtre d'assignation…
        $this->actingAs($this->admin)
            ->post(route('sites.assign', $this->site), ['agents' => [$this->jinah->id]])
            ->assertRedirect();

        $assignment = $this->site->assignmentOf($this->jinah);
        $this->assertSame('in_progress', $assignment->status);
        $this->assertNull($assignment->completed_at);
        $this->assertSame(1, $this->site->agentAssignments()->count());
        $this->actingAs($this->jinah)->get(route('sites.index'))->assertSee($this->site->name);

        // … ou avec « Réassigner ».
        $assignment->forceFill(['status' => 'done', 'completed_at' => now()])->save();

        $this->actingAs($this->admin)
            ->post(route('sites.assignment-status', [$this->site, $this->jinah]), ['status' => 'in_progress'])
            ->assertRedirect();

        $this->assertSame('in_progress', $this->site->assignmentOf($this->jinah)->status);
        $this->actingAs($this->jinah)->get(route('articles.index', ['site' => $this->site->id]))->assertOk();
    }

    public function test_le_dashboard_d_un_agent_le_renvoie_vers_ses_articles(): void
    {
        $this->actingAs($this->jinah)
            ->get(route('dashboard'))
            ->assertRedirect(route('articles.index'));
    }

    public function test_retirer_un_agent_d_un_site_lui_retire_l_acces_et_libere_ses_articles(): void
    {
        $this->assignSite($this->jinah, $this->site);
        $this->assignSite($this->daniella, $this->site);
        $article = $this->lockFor(WordpressArticle::factory()->for($this->site, 'site')->create(), $this->jinah);

        $this->actingAs($this->admin)
            ->post(route('sites.assign', $this->site), ['agents' => [$this->daniella->id]])
            ->assertRedirect();

        $this->assertNull($this->site->assignmentOf($this->jinah));
        $this->assertNull($article->fresh()->assigned_to);
        $this->actingAs($this->jinah)->get(route('articles.show', $article))->assertForbidden();
    }

    public function test_l_admin_voit_le_travail_de_chaque_agent_sur_un_site(): void
    {
        $this->assignSite($this->jinah, $this->site);
        $this->assignSite($this->daniella, $this->site);
        $this->lockFor(WordpressArticle::factory()->for($this->site, 'site')->create(['title' => 'Guide des bagues']), $this->daniella);
        ArticleStatusHistory::create([
            'user_id' => $this->admin->id,
            'wordpress_site_id' => $this->site->id,
            'site_name' => $this->site->name,
            'status' => WordpressArticle::AUDIT_FIXED,
            'agent' => 'Jinah',
            'agent_user_id' => $this->jinah->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('sites.index'))
            ->assertOk()
            ->assertSee('Agents assignés')
            ->assertSeeInOrder(['Daniella', '0 corrigé(s) · 1 en cours'])
            ->assertSeeInOrder(['Jinah', '1 corrigé(s) · 0 en cours']);

        $this->actingAs($this->admin)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertSee('En cours par Daniella');
    }

    public function test_l_application_password_est_stockee_chiffree_dans_sa_propre_table(): void
    {
        $credential = SiteCredential::where('wordpress_site_id', $this->site->id)->firstOrFail();
        $raw = DB::table('site_credentials')->where('id', $credential->id)->value('application_password');

        $this->assertSame('abcd1234abcd1234abcd1234', $credential->application_password);
        $this->assertNotSame('abcd1234abcd1234abcd1234', $raw);
        $this->assertFalse(Schema::hasColumn('wordpress_sites', 'application_password'));
    }

    public function test_l_admin_n_attribue_un_article_qu_a_un_agent_assigne_au_site(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create();

        $this->actingAs($this->admin)
            ->postJson(route('articles.agent', $article), ['agent' => $this->jinah->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jinah n’est pas assigné à ce site : impossible de lui attribuer cet article.');

        $this->assignSite($this->jinah, $this->site);

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

    /** Seul l'Admin consulte les statistiques, y compris par URL directe. */
    public function test_un_agent_n_accede_ni_aux_statistiques_ni_aux_audits(): void
    {
        $this->correction($this->daniella, 'Article de Daniella');

        $this->actingAs($this->jinah);

        $this->get(route('statistics.index'))->assertForbidden();
        $this->get(route('statistics.index', ['agent' => $this->jinah->id]))->assertForbidden();
        $this->get(route('statistics.agent', $this->daniella))->assertForbidden();
        $this->get(route('statistics.agent', $this->jinah))->assertForbidden();
        $this->getJson(route('statistics.series'))->assertForbidden();
        $this->get(route('audits.index'))->assertForbidden();
        $this->postJson(route('audits.run'))->assertForbidden();
    }

    /**
     * Jinah prend 2 articles « À corriger » : En cours 2. Elle en passe un en
     * « Corrigé » : Corrigés 1, En cours 1 (vue « Par agent » de l'Admin).
     */
    public function test_en_cours_et_corriges_de_l_agent_suivent_le_statut(): void
    {
        $this->assignSite($this->jinah, $this->site);

        [$a, $b] = WordpressArticle::factory()->for($this->site, 'site')->count(2)->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ])->all();

        foreach ([$a, $b] as $article) {
            $this->actingAs($this->jinah)->postJson(route('articles.take', $article))->assertOk();
        }

        $row = $this->jinahRow();
        $this->assertSame(2, $row['in_progress']);
        $this->assertSame(0, $row['fixed']);

        $this->actingAs($this->jinah)
            ->postJson(route('articles.status', $a), ['status' => WordpressArticle::STATUS_DONE])
            ->assertOk();

        $row = $this->jinahRow();
        $this->assertSame(1, $row['in_progress']);
        $this->assertSame(1, $row['fixed']);
    }

    /**
     * Jinah corrige deux articles ; l'Admin en réassigne un : la correction
     * déjà faite reste à son actif, et l'article repasse « En cours ».
     */
    public function test_une_correction_reste_acquise_apres_reassignation(): void
    {
        $this->assignSite($this->jinah, $this->site);

        foreach (range(1, 2) as $i) {
            $article = WordpressArticle::factory()->for($this->site, 'site')->create([
                'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
            ]);

            $this->actingAs($this->jinah)->postJson(route('articles.take', $article))->assertOk();
            $this->actingAs($this->jinah)
                ->postJson(route('articles.status', $article), ['status' => WordpressArticle::STATUS_DONE])
                ->assertOk();

            $this->assertNull($article->fresh()->assigned_to);
            $this->assertTrue($article->fresh()->isCompleted());
        }

        $row = $this->jinahRow();
        $this->assertSame(2, $row['fixed']);
        $this->assertSame(0, $row['in_progress']);

        $this->actingAs($this->admin)->get(route('sites.index'))
            ->assertSeeInOrder(['Jinah', '2 corrigé(s) · 0 en cours']);

        $this->actingAs($this->admin)
            ->postJson(route('articles.reassign', $article), ['agent' => $this->jinah->id, 'comment' => 'Encore une image floue.'])
            ->assertOk();

        $row = $this->jinahRow();
        $this->assertSame(2, $row['fixed']);
        $this->assertSame(1, $row['in_progress']);
    }

    public function test_l_admin_retire_les_sites_supprimes_des_statistiques(): void
    {
        $this->correction($this->jinah, 'Article du site connecté');
        ArticleStatusHistory::create([
            'user_id' => $this->admin->id,
            'wordpress_site_id' => null,
            'site_name' => 'ancien-site.fr',
            'status' => WordpressArticle::AUDIT_FIXED,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('statistics.index'))
            ->assertSee('ancien-site.fr')
            ->assertSee('Supprimer les sites supprimés');

        // Un agent ne peut pas le faire, même en appelant la route.
        $this->actingAs($this->jinah)->delete(route('statistics.purge-archived'))->assertForbidden();
        $this->assertSame(2, ArticleStatusHistory::count());

        $this->actingAs($this->admin)->delete(route('statistics.purge-archived'))->assertRedirect(route('statistics.index'));

        $this->assertSame(1, ArticleStatusHistory::count());
        $this->actingAs($this->admin)->get(route('statistics.index'))
            ->assertDontSee('ancien-site.fr')
            ->assertDontSee('Supprimer les sites supprimés');
    }

    public function test_la_commande_remet_toutes_les_statistiques_a_zero(): void
    {
        $this->assignSite($this->jinah, $this->site);
        $this->correction($this->jinah, 'Article de Jinah');
        $this->lockFor(WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]), $this->jinah);

        $this->artisan('stats:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, ArticleStatusHistory::count());
        $this->assertSame(0, WordpressArticle::query()->whereNotNull('assigned_to')->count());

        $row = $this->jinahRow();
        $this->assertSame(0, $row['fixed']);
        $this->assertSame(0, $row['in_progress']);
    }

    public function test_l_admin_voit_chaque_agent_et_le_detail_de_ses_articles(): void
    {
        $this->assignSite($this->jinah, $this->site);
        $this->assignSite($this->daniella, $this->site);

        foreach ([[$this->daniella, 'Article de Daniella'], [$this->jinah, 'Article de Jinah']] as [$agent, $title]) {
            $article = WordpressArticle::factory()->for($this->site, 'site')->create([
                'title' => $title,
                'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
            ]);
            $this->lockFor($article, $agent);
            $this->actingAs($agent)
                ->postJson(route('articles.status', $article), ['status' => WordpressArticle::STATUS_DONE])
                ->assertOk();
        }

        $this->actingAs($this->admin)
            ->get(route('statistics.index'))
            ->assertOk()
            ->assertSee('Par agent')
            ->assertSee('Daniella')
            ->assertSee('Jinah')
            ->assertSee(route('statistics.agent', $this->daniella), false);

        $this->actingAs($this->admin)
            ->get(route('statistics.agent', $this->daniella))
            ->assertOk()
            ->assertSee('Articles de Daniella')
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

    /**
     * @return array<string, mixed>
     */
    protected function jinahRow(): array
    {
        return collect($this->actingAs($this->admin)->get(route('statistics.index'))->assertOk()->viewData('agentRows'))
            ->firstWhere('agent', $this->jinah->id);
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
