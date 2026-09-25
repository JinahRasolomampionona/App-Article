<?php

namespace Tests\Feature;

use App\Models\ArticleAssignment;
use App\Models\ArticleAuditIssue;
use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\Assignment\ArticleLockedException;
use App\Services\Assignment\ArticleLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Prise en charge des articles : un seul agent actif par article, contrôlé
 * côté serveur, avec expiration et heartbeat.
 */
class ArticleLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $daniella;

    protected User $jinah;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['articleguard.locks.ttl_minutes' => 30]);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $this->daniella = User::factory()->create(['name' => 'Daniella']);
        $this->jinah = User::factory()->create(['name' => 'Jinah']);

        $this->site = WordpressSite::factory()->for($this->admin)->create(['url' => 'https://bijouteries.top']);

        // Chaque agent a connecté le site avec ses propres identifiants.
        $this->connectSite($this->daniella, $this->site);
        $this->connectSite($this->jinah, $this->site);
        session(['articleguard.current_site' => $this->site->id]);
    }

    /* --- Prendre -------------------------------------------------------------- */

    public function test_un_agent_prend_un_article_disponible(): void
    {
        $article = $this->article();

        $this->actingAs($this->daniella)
            ->postJson(route('articles.take', $article))
            ->assertOk()
            ->assertJsonPath('state', 'mine')
            ->assertJsonPath('agent', 'Daniella')
            ->assertJsonPath('edit_url', route('articles.edit', $article));

        $article->refresh();

        $this->assertSame($this->daniella->id, $article->assigned_to);
        $this->assertTrue($article->isLockedBy($this->daniella));
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $article->lock_expires_at->timestamp, 5);

        $this->assertDatabaseHas('article_assignments', [
            'wordpress_article_id' => $article->id,
            'user_id' => $this->daniella->id,
            'agent_name' => 'Daniella',
            'released_at' => null,
        ]);
    }

    public function test_deux_agents_ne_peuvent_pas_prendre_le_meme_article(): void
    {
        $article = $this->article();

        $this->actingAs($this->daniella)->postJson(route('articles.take', $article))->assertOk();

        $this->actingAs($this->jinah)
            ->postJson(route('articles.take', $article))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cet article est actuellement traité par Daniella.');

        $this->assertSame($this->daniella->id, $article->fresh()->assigned_to);
        $this->assertSame(1, ArticleAssignment::count());
    }

    /**
     * Course : Jinah a lu l'article « disponible » juste avant que Daniella ne
     * le prenne. Le service relit la ligne verrouillée en base et refuse.
     */
    public function test_le_service_refuse_une_prise_concurrente_meme_avec_un_etat_perime(): void
    {
        $article = $this->article();
        $stale = $article->fresh();

        app(ArticleLockService::class)->take($article, $this->daniella);

        try {
            app(ArticleLockService::class)->take($stale, $this->jinah);
            $this->fail('La seconde prise aurait dû être refusée.');
        } catch (ArticleLockedException $e) {
            $this->assertSame('Cet article est actuellement traité par Daniella.', $e->getMessage());
        }

        $this->assertSame($this->daniella->id, $article->fresh()->assigned_to);
    }

    public function test_un_verrou_expire_rend_l_article_disponible(): void
    {
        $article = $this->lockFor($this->article(), $this->daniella, minutes: 30);

        $this->travel(31)->minutes();

        $this->assertFalse($article->fresh()->isLocked());

        $this->actingAs($this->jinah)
            ->postJson(route('articles.take', $article))
            ->assertOk()
            ->assertJsonPath('agent', 'Jinah');
    }

    public function test_la_prise_apres_expiration_clot_l_ancienne_prise_en_charge(): void
    {
        $article = $this->article();
        app(ArticleLockService::class)->take($article, $this->daniella);

        $this->travel(31)->minutes();
        app(ArticleLockService::class)->take($article, $this->jinah);

        $previous = ArticleAssignment::where('user_id', $this->daniella->id)->firstOrFail();

        $this->assertSame(ArticleAssignment::REASON_EXPIRED, $previous->release_reason);
        $this->assertNotNull($previous->released_at);
    }

    /* --- Liste « Agent » : droits ------------------------------------------- */

    public function test_un_agent_ne_peut_pas_attribuer_un_article_a_un_autre(): void
    {
        $article = $this->article();

        $this->actingAs($this->jinah)
            ->postJson(route('articles.agent', $article), ['agent' => $this->daniella->id])
            ->assertForbidden();

        $this->assertNull($article->fresh()->assigned_to);
    }

    public function test_un_agent_prend_pour_lui_via_la_liste(): void
    {
        $article = $this->article();

        $this->actingAs($this->jinah)
            ->postJson(route('articles.agent', $article), ['agent' => $this->jinah->id])
            ->assertOk()
            ->assertJsonPath('state', 'mine');
    }

    public function test_un_agent_ne_peut_pas_liberer_l_article_d_un_autre(): void
    {
        $article = $this->lockFor($this->article(), $this->daniella);

        $this->actingAs($this->jinah)
            ->postJson(route('articles.release', $article))
            ->assertForbidden();

        $this->actingAs($this->jinah)
            ->postJson(route('articles.agent', $article), ['agent' => null])
            ->assertForbidden();

        $this->assertSame($this->daniella->id, $article->fresh()->assigned_to);
    }

    public function test_un_agent_libere_son_article(): void
    {
        $article = $this->article();
        app(ArticleLockService::class)->take($article, $this->daniella);

        $this->actingAs($this->daniella)
            ->postJson(route('articles.release', $article))
            ->assertOk()
            ->assertJsonPath('state', 'available');

        $this->assertNull($article->fresh()->assigned_to);
        $this->assertSame(
            ArticleAssignment::REASON_RELEASED,
            ArticleAssignment::firstOrFail()->release_reason,
        );
    }

    public function test_l_admin_peut_attribuer_et_liberer_n_importe_quel_article(): void
    {
        $article = $this->lockFor($this->article(), $this->daniella);

        $this->actingAs($this->admin)
            ->postJson(route('articles.agent', $article), ['agent' => $this->jinah->id])
            ->assertOk()
            ->assertJsonPath('agent', 'Jinah');

        $this->assertSame($this->jinah->id, $article->fresh()->assigned_to);

        $this->actingAs($this->admin)
            ->postJson(route('articles.release', $article))
            ->assertOk();

        $this->assertNull($article->fresh()->assigned_to);
    }

    public function test_un_compte_desactive_ne_peut_pas_recevoir_d_article(): void
    {
        $this->jinah->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)
            ->postJson(route('articles.agent', $this->article()), ['agent' => $this->jinah->id])
            ->assertStatus(422);
    }

    /* --- Édition --------------------------------------------------------------- */

    public function test_l_editeur_est_en_consultation_pour_un_autre_agent(): void
    {
        $article = $this->lockFor($this->article(), $this->daniella);

        $this->actingAs($this->jinah)
            ->get(route('articles.edit', $article))
            ->assertOk()
            ->assertSee('data-readonly="1"', false)
            ->assertSee('En cours par Daniella')
            ->assertDontSee('data-save', false);

        $this->actingAs($this->daniella)
            ->get(route('articles.edit', $article))
            ->assertOk()
            ->assertSee('data-readonly="0"', false)
            ->assertSee('Terminer la correction')
            ->assertSee('Libérer l’article');
    }

    public function test_une_requete_forgee_ne_modifie_pas_un_article_verrouille(): void
    {
        Http::fake();
        $article = $this->lockFor($this->article(), $this->daniella);

        $this->actingAs($this->jinah)
            ->putJson(route('articles.update', $article), ['title' => 'Piraté', 'content' => '<p>x</p>'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cet article est actuellement traité par Daniella.');

        // Même l'Admin doit d'abord prendre l'article : pas d'édition simultanée.
        $this->actingAs($this->admin)
            ->putJson(route('articles.update', $article), ['title' => 'Piraté', 'content' => '<p>x</p>'])
            ->assertStatus(409);

        $this->actingAs($this->jinah)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::AUDIT_FIXED])
            ->assertStatus(409);

        Http::assertNothingSent();
        $this->assertNotSame('Piraté', $article->fresh()->title);
    }

    public function test_un_verrou_expire_interdit_l_enregistrement(): void
    {
        Http::fake();
        $article = $this->lockFor($this->article(), $this->daniella, minutes: 5);

        $this->travel(6)->minutes();

        $this->actingAs($this->daniella)
            ->putJson(route('articles.update', $article), ['title' => 'Trop tard', 'content' => '<p>x</p>'])
            ->assertStatus(409);

        Http::assertNothingSent();
    }

    /* --- Statut manuel ----------------------------------------------------------- */

    public function test_le_statut_est_un_selecteur_desactive_si_un_autre_agent_traite_l_article(): void
    {
        $free = $this->article(['title' => 'Libre', 'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX]);
        $taken = $this->lockFor($this->article(['title' => 'Pris', 'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX]), $this->daniella);

        $html = $this->actingAs($this->jinah)
            ->getJson(route('articles.index', ['site' => $this->site->id, 'partial' => 1]))
            ->json('html');

        $rows = collect(explode('<tr data-article-id=', $html))->slice(1)->keyBy(fn ($row) => (int) trim(strtok($row, '>'), '"'));

        $this->assertStringContainsString('ag-status-select', $rows[$free->id]);
        $this->assertStringNotContainsString('disabled', explode('</select>', explode('ag-status-select', $rows[$free->id])[1])[0]);
        $this->assertStringContainsString('ag-status-select', $rows[$taken->id]);
        $this->assertStringContainsString('title="En cours par Daniella"', $rows[$taken->id]);
    }

    public function test_declarer_corrige_credite_l_agent_et_revenir_retire_la_correction(): void
    {
        $article = $this->article(['audit_status' => WordpressArticle::AUDIT_NEEDS_FIX, 'issues_count' => 1]);

        // Article non pris : Jinah peut déclarer son statut directement.
        $this->actingAs($this->jinah)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::AUDIT_FIXED])
            ->assertOk();

        $entry = ArticleStatusHistory::firstOrFail();
        $this->assertSame($this->jinah->id, $entry->agent_user_id);
        $this->assertTrue($entry->resolved_manually);

        $this->actingAs($this->jinah)
            ->get(route('statistics.index'))
            ->assertOk()
            ->assertViewHas('me', fn ($me) => $me['corrected'] === 1);

        $this->actingAs($this->jinah)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::AUDIT_NEEDS_FIX])
            ->assertOk();

        $this->assertSame(0, ArticleStatusHistory::count());
    }

    public function test_un_agent_ne_change_pas_le_statut_d_un_article_pris_par_un_autre(): void
    {
        $article = $this->lockFor($this->article(['audit_status' => WordpressArticle::AUDIT_NEEDS_FIX]), $this->daniella);

        $this->actingAs($this->jinah)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::AUDIT_FIXED])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cet article est actuellement traité par Daniella.');

        $this->assertSame(WordpressArticle::AUDIT_NEEDS_FIX, $article->fresh()->audit_status);
    }

    /* --- Heartbeat ------------------------------------------------------------ */

    public function test_le_heartbeat_prolonge_le_verrou(): void
    {
        $article = $this->lockFor($this->article(), $this->daniella, minutes: 2);

        $this->travel(1)->minutes();

        $this->actingAs($this->daniella)
            ->postJson(route('articles.heartbeat', $article))
            ->assertOk();

        $this->assertEqualsWithDelta(
            now()->addMinutes(30)->timestamp,
            $article->fresh()->lock_expires_at->timestamp,
            5,
        );
    }

    public function test_le_heartbeat_signale_un_verrou_perdu(): void
    {
        $article = $this->lockFor($this->article(), $this->daniella);

        // L'Admin a réattribué l'article à Jinah pendant l'édition.
        app(ArticleLockService::class)->take($article, $this->jinah, by: $this->admin);

        $this->actingAs($this->daniella)
            ->postJson(route('articles.heartbeat', $article))
            ->assertStatus(409)
            ->assertJsonPath('lost', true)
            ->assertJsonPath('agent', 'Jinah');
    }

    /* --- Fin de correction ---------------------------------------------------- */

    public function test_terminer_relance_l_audit_enregistre_l_activite_et_libere(): void
    {
        Http::fake();
        Queue::fake();
        // Règles réseau coupées : le test porte sur le cycle de prise en charge.
        config(['articleguard.rules.featured_image' => false, 'articleguard.rules.broken_image' => false, 'articleguard.rules.image_blur' => false, 'articleguard.rules.image_relevance' => false]);

        $article = $this->article([
            'title' => 'Guide des bagues',
            'content' => '<h1>Guide des bagues</h1><h2>Section</h2><p>Texte.</p><img src="https://bijouteries.top/a.jpg" alt="Bague">',
            'featured_media_id' => 5,
            'featured_media_url' => 'https://bijouteries.top/une.jpg',
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
            'issues_count' => 1,
        ]);
        ArticleAuditIssue::create([
            'wordpress_article_id' => $article->id,
            'rule_type' => 'shortcode_detected',
            'severity' => 'warning',
            'message' => 'Shortcode détecté',
            'detected_at' => now()->subHour(),
        ]);

        app(ArticleLockService::class)->take($article, $this->daniella);

        $this->actingAs($this->daniella)
            ->postJson(route('articles.finish', $article))
            ->assertOk()
            ->assertJsonPath('issues_count', 0)
            ->assertJsonPath('status', WordpressArticle::AUDIT_FIXED);

        $article->refresh();

        $this->assertFalse($article->isLocked());
        $this->assertSame(WordpressArticle::AUDIT_FIXED, $article->audit_status);

        $assignment = ArticleAssignment::firstOrFail();
        $this->assertSame(ArticleAssignment::REASON_COMPLETED, $assignment->release_reason);
        $this->assertNotNull($assignment->completed_at);
        $this->assertSame(WordpressArticle::AUDIT_FIXED, $assignment->audit_result);
        $this->assertSame(0, $assignment->issues_remaining);

        // La correction est créditée à Daniella dans l'historique.
        $entry = ArticleStatusHistory::firstOrFail();
        $this->assertSame($this->daniella->id, $entry->agent_user_id);
        $this->assertSame('Daniella', $entry->agent);
    }

    public function test_terminer_est_refuse_a_qui_ne_detient_pas_l_article(): void
    {
        $article = $this->lockFor($this->article(), $this->daniella);

        $this->actingAs($this->jinah)
            ->postJson(route('articles.finish', $article))
            ->assertStatus(409);

        $this->assertTrue($article->fresh()->isLockedBy($this->daniella));
    }

    /* --- Tableau et rafraîchissement -------------------------------------------- */

    public function test_le_tableau_affiche_l_etat_de_traitement(): void
    {
        $this->lockFor($this->article(['title' => 'Guide des bagues']), $this->daniella);
        $this->article(['title' => 'Choisir un collier']);

        $this->actingAs($this->jinah)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertSee('En cours par Daniella')
            ->assertSee('Disponible')
            ->assertSee('data-poll-url', false);
    }

    public function test_le_sondage_renvoie_l_etat_des_lignes_affichees(): void
    {
        $mine = $this->lockFor($this->article(), $this->jinah);
        $other = $this->lockFor($this->article(), $this->daniella);
        $free = $this->article();

        $response = $this->actingAs($this->jinah)
            ->getJson(route('articles.assignments', ['ids' => [$mine->id, $other->id, $free->id]]))
            ->assertOk();

        $response->assertJsonPath("articles.{$mine->id}.state", 'mine')
            ->assertJsonPath("articles.{$other->id}.state", 'other')
            ->assertJsonPath("articles.{$other->id}.agent", 'Daniella')
            ->assertJsonPath("articles.{$free->id}.state", 'available');

        $this->assertStringContainsString('En cours par Daniella', $response->json("articles.{$other->id}.agent_html"));
    }

    public function test_le_filtre_disponibles_exclut_les_articles_pris(): void
    {
        $this->lockFor($this->article(['title' => 'Pris par Daniella']), $this->daniella);
        $this->article(['title' => 'Libre']);

        $this->actingAs($this->jinah)
            ->getJson(route('articles.index', ['site' => $this->site->id, 'agent' => 'none', 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->jinah)
            ->getJson(route('articles.index', ['site' => $this->site->id, 'agent' => $this->daniella->id, 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    /* --- Nettoyage ------------------------------------------------------------- */

    public function test_la_commande_libere_les_verrous_expires(): void
    {
        $article = $this->article();
        app(ArticleLockService::class)->take($article, $this->daniella);

        $this->travel(45)->minutes();

        $this->artisan('articles:release-expired')->assertSuccessful();

        $this->assertNull($article->fresh()->assigned_to);
        $this->assertSame(ArticleAssignment::REASON_EXPIRED, ArticleAssignment::firstOrFail()->release_reason);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function article(array $attributes = []): WordpressArticle
    {
        return WordpressArticle::factory()->for($this->site, 'site')->create($attributes);
    }
}
