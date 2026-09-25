<?php

namespace Tests\Feature;

use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\Stats\ArticleStatisticsService;
use App\Services\Stats\StatisticsFilter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Statistiques des articles : état courant, historique et survie des données
 * à la suppression d'un site.
 */
class ArticleStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected WordpressSite $site;

    protected ArticleStatisticsService $statistics;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 10:00:00'));

        $this->user = User::factory()->admin()->create();
        $this->site = WordpressSite::factory()->for($this->user)->create([
            'name' => 'bijouteries.top',
            'url' => 'https://bijouteries.top',
        ]);
        $this->statistics = app(ArticleStatisticsService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /* --- État courant --------------------------------------------------------- */

    public function test_la_vue_d_ensemble_separe_les_articles_conformes_des_articles_a_corriger(): void
    {
        $this->articles(340, WordpressArticle::AUDIT_OK);
        $this->articles(60, WordpressArticle::AUDIT_FIXED);
        $this->articles(60, WordpressArticle::AUDIT_NEEDS_FIX);

        $overview = $this->statistics->overview(StatisticsFilter::forAdmin($this->user));

        $this->assertSame(460, $overview['articles']);
        $this->assertSame(400, $overview['corrected']);
        $this->assertSame(340, $overview['ok']);
        $this->assertSame(60, $overview['fixed']);
        $this->assertSame(60, $overview['needs_fix']);
        $this->assertSame(87, $overview['rate']);
    }

    public function test_le_detail_par_site_reprend_les_memes_compteurs(): void
    {
        $this->articles(4, WordpressArticle::AUDIT_OK);
        $this->articles(1, WordpressArticle::AUDIT_NEEDS_FIX);

        $rows = $this->statistics->perSite(StatisticsFilter::forAdmin($this->user));

        $this->assertCount(1, $rows);
        $this->assertSame('bijouteries.top', $rows[0]['name']);
        $this->assertSame(5, $rows[0]['articles']);
        $this->assertSame(4, $rows[0]['corrected']);
        $this->assertSame(1, $rows[0]['needs_fix']);
        $this->assertSame(80, $rows[0]['rate']);
    }

    public function test_l_espace_partage_compte_tous_les_sites_et_se_filtre_par_site(): void
    {
        $other = User::factory()->admin()->create();
        $otherSite = WordpressSite::factory()->for($other)->create(['url' => 'https://ailleurs.test']);
        WordpressArticle::factory()->count(3)->for($otherSite, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_OK,
        ]);

        $this->articles(2, WordpressArticle::AUDIT_OK);

        $filter = StatisticsFilter::forAdmin($this->user);

        $this->assertSame(5, $this->statistics->overview($filter)['articles']);
        $this->assertSame(2, $this->statistics->overview($filter->withSite($this->site->id))['articles']);
    }

    /* --- Détection des corrections ------------------------------------------- */

    public function test_un_article_declare_corrige_entre_dans_l_historique(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'title' => 'Guide des bagues',
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);

        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $entry = ArticleStatusHistory::firstOrFail();

        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame('bijouteries.top', $entry->site_name);
        $this->assertSame('Guide des bagues', $entry->article_title);
        $this->assertSame(WordpressArticle::AUDIT_FIXED, $entry->status);
        $this->assertTrue($entry->resolved_manually);
        $this->assertSame('2026-09-23', $entry->recorded_at->format('Y-m-d'));
    }

    public function test_un_statut_inchange_ne_cree_pas_de_doublon(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);

        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);
        $article->refresh()->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $this->assertSame(1, ArticleStatusHistory::count());
    }

    public function test_un_retour_a_corriger_puis_une_nouvelle_correction_creent_deux_entrees(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);

        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);
        $article->refresh()->applyManualStatus(WordpressArticle::AUDIT_NEEDS_FIX);
        $article->refresh()->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $this->assertSame(2, ArticleStatusHistory::count());
    }

    /* --- Survie à la suppression du site -------------------------------------- */

    public function test_l_historique_survit_a_la_suppression_du_site(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);
        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $this->site->delete();

        $entry = ArticleStatusHistory::firstOrFail();

        $this->assertSame(1, ArticleStatusHistory::count());
        // Le lien est rompu, mais le nom du site reste lisible.
        $this->assertNull($entry->wordpress_site_id);
        $this->assertNull($entry->wordpress_article_id);
        $this->assertSame('bijouteries.top', $entry->site_name);
        $this->assertTrue($entry->siteWasDeleted());
    }

    public function test_un_site_supprime_apparait_comme_archive(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);
        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $this->site->delete();

        $archived = $this->statistics->archivedSites(StatisticsFilter::forAdmin($this->user));

        $this->assertCount(1, $archived);
        $this->assertSame('bijouteries.top', $archived[0]['name']);
        $this->assertSame(1, $archived[0]['corrected']);
        $this->assertTrue($archived[0]['archived']);
        // Le site n'a plus d'état courant.
        $this->assertSame([], $this->statistics->perSite(StatisticsFilter::forAdmin($this->user)));
    }

    public function test_la_suppression_du_compte_efface_bien_l_historique(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);
        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $this->user->delete();

        $this->assertSame(0, ArticleStatusHistory::count());
    }

    /* --- Reprise de l'existant ------------------------------------------------ */

    public function test_la_reprise_inscrit_les_articles_deja_conformes(): void
    {
        WordpressArticle::factory()->for($this->site, 'site')->create([
            'title' => 'Déjà conforme',
            'audit_status' => WordpressArticle::AUDIT_OK,
            'last_audited_at' => CarbonImmutable::parse('2026-09-20 12:00:00'),
        ]);
        WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);

        $this->artisan('stats:sync')->assertSuccessful();

        $entry = ArticleStatusHistory::firstOrFail();

        // Seul l'article conforme est repris, daté de sa dernière analyse.
        $this->assertSame(1, ArticleStatusHistory::count());
        $this->assertSame('Déjà conforme', $entry->article_title);
        $this->assertSame('bijouteries.top', $entry->site_name);
        $this->assertSame('2026-09-20', $entry->recorded_at->format('Y-m-d'));
    }

    public function test_la_reprise_est_rejouable_sans_creer_de_doublon(): void
    {
        $this->articles(3, WordpressArticle::AUDIT_OK);

        $this->artisan('stats:sync')->assertSuccessful();
        $this->artisan('stats:sync')->assertSuccessful();

        $this->assertSame(3, ArticleStatusHistory::count());
    }

    /* --- Écran --------------------------------------------------------------- */

    public function test_la_page_statistiques_affiche_les_compteurs_et_l_historique(): void
    {
        $this->articles(4, WordpressArticle::AUDIT_OK);
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'title' => 'Choisir un collier',
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);
        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $this->actingAs($this->user)
            ->get(route('statistics.index'))
            ->assertOk()
            ->assertSee('Statistiques', false)
            ->assertSee('OK / Corrigés', false)
            ->assertSee('Non corrigés', false)
            ->assertSee('Historique des corrections', false)
            ->assertSee('bijouteries.top', false)
            ->assertSee('Choisir un collier', false)
            ->assertSee('Corrigé manuellement', false)
            ->assertSee('23/09/2026', false);
    }

    public function test_les_articles_non_corriges_sont_listes(): void
    {
        WordpressArticle::factory()->for($this->site, 'site')->create([
            'title' => 'Entretien des bracelets',
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
            'issues_count' => 3,
        ]);

        $this->actingAs($this->user)
            ->get(route('statistics.index'))
            ->assertOk()
            ->assertSee('Articles non corrigés', false)
            ->assertSee('Entretien des bracelets', false);
    }

    public function test_le_menu_statistiques_est_place_au_dessus_des_parametres(): void
    {
        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertOk()->assertSee('Statistiques', false);

        $html = $response->getContent();

        $this->assertLessThan(
            strpos($html, 'Paramètres'),
            strpos($html, 'Statistiques'),
            'L’entrée Statistiques doit précéder Paramètres dans la navigation.',
        );
    }

    public function test_un_filtre_de_site_etranger_est_ignore(): void
    {
        $other = WordpressSite::factory()->create(['url' => 'https://ailleurs.test']);

        $this->articles(2, WordpressArticle::AUDIT_NEEDS_FIX);

        // Le filtre est rejeté : la page reste celle du compte, tous sites.
        $this->actingAs($this->user)
            ->get(route('statistics.index', ['site' => $other->id]))
            ->assertOk()
            ->assertSee('bijouteries.top', false);
    }

    /* --- Utilitaires ---------------------------------------------------------- */

    protected function articles(int $count, string $status): void
    {
        WordpressArticle::factory()->count($count)->for($this->site, 'site')->create([
            'audit_status' => $status,
        ]);
    }
}
