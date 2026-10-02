<?php

namespace Tests\Feature;

use App\Models\ArticleAuditIssue;
use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\Assignment\ArticleCompletionService;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
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

    public function test_le_detail_par_site_reprend_les_compteurs_des_quatre_cartes(): void
    {
        $this->articles(4, WordpressArticle::AUDIT_OK);
        $this->articles(1, WordpressArticle::AUDIT_NEEDS_FIX);

        // Deux articles déclarés corrigés par un agent : l'un conforme, l'autre
        // encore « À corriger » à l'audit — tous deux comptent en « Corrigés ».
        $done = WordpressArticle::query()->orderBy('id')->take(1)->get()
            ->merge(WordpressArticle::query()->where('audit_status', WordpressArticle::AUDIT_NEEDS_FIX)->get());
        $done->each(fn (WordpressArticle $article) => $article->forceFill(['completed_at' => now()->subDay()])->save());

        $rows = $this->statistics->perSite(StatisticsFilter::forAdmin($this->user));
        $cards = $this->statistics->statusCards(null);

        $this->assertCount(1, $rows);
        $this->assertSame('bijouteries.top', $rows[0]['name']);
        $this->assertSame(5, $rows[0]['articles']);
        $this->assertSame(0, $rows[0]['needs_fix']);
        $this->assertSame(3, $rows[0]['to_review']);
        $this->assertSame(2, $rows[0]['fixed']);
        $this->assertNotNull($rows[0]['last_corrected_at']);

        // Exactement les chiffres des cartes.
        $this->assertSame(
            [$cards['total'], $cards['needs_fix'], $cards['to_review'], $cards['fixed']],
            [$rows[0]['articles'], $rows[0]['needs_fix'], $rows[0]['to_review'], $rows[0]['fixed']],
        );
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

        $this->declareFixed($article);

        $entry = ArticleStatusHistory::firstOrFail();

        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame('bijouteries.top', $entry->site_name);
        $this->assertSame('Guide des bagues', $entry->article_title);
        $this->assertSame(WordpressArticle::AUDIT_FIXED, $entry->status);
        $this->assertTrue($entry->resolved_manually);
        $this->assertSame('Daniella', $entry->agent);
        $this->assertSame('2026-09-23', $entry->recorded_at->format('Y-m-d'));
    }

    public function test_un_article_deja_corrige_ne_cree_pas_de_doublon(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);

        $this->declareFixed($article);
        app(ArticleCompletionService::class)->complete($article->refresh(), $this->user);

        $this->assertSame(1, ArticleStatusHistory::count());
    }

    /** L'audit constate, il ne crédite personne. */
    public function test_un_audit_sans_probleme_ne_cree_pas_d_historique(): void
    {
        config(['articleguard.rules.featured_image' => false, 'articleguard.rules.broken_image' => false, 'articleguard.rules.image_blur' => false, 'articleguard.rules.image_relevance' => false]);

        $article = WordpressArticle::factory()->for($this->site, 'site')
            ->withContent('<h2>Section</h2><p>Texte.</p><img src="https://bijouteries.top/a.jpg" alt="a">')
            ->create(['title' => 'Court', 'audit_status' => WordpressArticle::AUDIT_PENDING]);

        app(AuditService::class)->run($article, AuditSettings::forUser($this->user), allowNetwork: false);

        $this->assertNotSame(WordpressArticle::AUDIT_PENDING, $article->fresh()->audit_status);
        $this->assertSame(0, ArticleStatusHistory::count());
    }

    /* --- Cartes -------------------------------------------------------------- */

    public function test_les_cartes_suivent_le_statut_affiche(): void
    {
        $this->articles(3, WordpressArticle::AUDIT_NEEDS_FIX);
        $this->articles(2, WordpressArticle::AUDIT_OK);
        $this->articles(1, WordpressArticle::AUDIT_FIXED);
        $this->articles(1, WordpressArticle::AUDIT_PENDING);
        $done = WordpressArticle::factory()->for($this->site, 'site')->create(['audit_status' => WordpressArticle::AUDIT_NEEDS_FIX]);
        $this->declareFixed($done);

        $cards = $this->statistics->statusCards($this->site->id);

        $this->assertSame(8, $cards['total']);
        $this->assertSame(3, $cards['needs_fix']);
        $this->assertSame(3, $cards['to_review']);
        $this->assertSame(1, $cards['fixed']);
        $this->assertSame(1, $cards['pending']);
    }

    /* --- Survie à la suppression du site -------------------------------------- */

    public function test_l_historique_survit_a_la_suppression_du_site(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);
        $this->declareFixed($article);

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
        $this->declareFixed($article);

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
        $this->declareFixed($article);

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

    public function test_la_page_statistiques_affiche_les_cartes_par_agent_et_par_site(): void
    {
        $this->articles(4, WordpressArticle::AUDIT_OK);
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'title' => 'Choisir un collier',
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);
        $this->declareFixed($article);

        $this->actingAs($this->user)
            ->get(route('statistics.index'))
            ->assertOk()
            ->assertSee('Total articles', false)
            ->assertSee('À vérifier', false)
            ->assertSee('Corrigés', false)
            ->assertSee('Par agent', false)
            ->assertSee('Par site', false)
            ->assertSee('bijouteries.top', false)
            ->assertSee('Daniella', false)
            // Blocs retirés.
            ->assertDontSee('Historique des corrections', false)
            ->assertDontSee('Articles non corrigés', false)
            ->assertDontSee('Articles corrigés dans le temps', false);
    }

    public function test_les_cartes_menent_aux_articles_filtres(): void
    {
        $this->articles(1, WordpressArticle::AUDIT_NEEDS_FIX);

        $response = $this->actingAs($this->user)
            ->get(route('statistics.index', ['site' => $this->site->id]))
            ->assertOk();

        foreach (['needs_fix', 'to_review', 'fixed'] as $status) {
            $response->assertSee(e(route('articles.index', ['site' => $this->site->id, 'status' => $status])), false);
        }

        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(e(route('articles.index', ['site' => $this->site->id, 'status' => 'to_review'])), false)
            ->assertDontSee('Répartition des problèmes', false)
            ->assertDontSee('Articles à corriger en priorité', false);
    }

    public function test_voir_un_agent_liste_ses_articles_et_les_erreurs_corrigees(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'title' => 'Choisir un collier',
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
            'issues_count' => 1,
        ]);
        ArticleAuditIssue::create([
            'wordpress_article_id' => $article->id,
            'rule_type' => 'long_title',
            'severity' => 'warning',
            'message' => 'Titre trop long',
            'detected_at' => now(),
        ]);
        $agent = $this->declareFixed($article);

        $inProgress = WordpressArticle::factory()->for($this->site, 'site')->create([
            'title' => 'Entretien des bracelets',
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);
        $this->lockFor($inProgress, $agent);

        $this->actingAs($this->user)
            ->get(route('statistics.agent', $agent))
            ->assertOk()
            ->assertSee('Articles de Daniella', false)
            ->assertSee('Retour aux statistiques', false)
            ->assertSee('href="'.route('statistics.index').'"', false)
            ->assertSee('bijouteries.top', false)
            ->assertSee('Choisir un collier', false)
            ->assertSee('Titre trop long', false)
            ->assertSee('Entretien des bracelets', false)
            ->assertSee('En cours', false)
            ->assertSee('Réassigner', false)
            ->assertSee('Commenter', false);
    }

    public function test_voir_un_agent_se_filtre_par_date_de_correction(): void
    {
        $old = WordpressArticle::factory()->for($this->site, 'site')->create(['title' => 'Corrigé en août']);
        $agent = $this->declareFixed($old);
        $old->forceFill(['completed_at' => '2026-08-12 10:00:00'])->save();

        $recent = WordpressArticle::factory()->for($this->site, 'site')->create(['title' => 'Corrigé en septembre']);
        $recent->forceFill(['completed_by' => $agent->id, 'completed_at' => '2026-09-20 15:30:00'])->save();

        $inProgress = WordpressArticle::factory()->for($this->site, 'site')->create(['title' => 'Encore en cours']);
        $this->lockFor($inProgress, $agent);

        // Un jour précis.
        $this->actingAs($this->user)
            ->get(route('statistics.agent', [$agent, 'date' => '2026-09-20']))
            ->assertOk()
            ->assertSee('Corrigé en septembre', false)
            ->assertDontSee('Corrigé en août', false)
            ->assertDontSee('Encore en cours', false)
            ->assertSee('Articles corrigés le', false);

        // Combiné au filtre de site.
        $this->actingAs($this->user)
            ->get(route('statistics.agent', [$agent, 'site' => $this->site->id, 'date' => '2026-08-12']))
            ->assertSee('Corrigé en août', false)
            ->assertDontSee('Corrigé en septembre', false);

        // Un jour sans correction.
        $this->actingAs($this->user)
            ->get(route('statistics.agent', [$agent, 'date' => '2026-09-21']))
            ->assertSee('n’a corrigé aucun article ce jour-là', false);

        // Sans date : articles en cours et corrigés, comme avant.
        $this->actingAs($this->user)
            ->get(route('statistics.agent', $agent))
            ->assertSee('Encore en cours', false)
            ->assertSee('Corrigé en août', false);
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

    /**
     * Daniella prend l'article et le déclare corrigé.
     */
    protected function declareFixed(WordpressArticle $article): User
    {
        $agent = User::query()->where('name', 'Daniella')->first()
            ?? User::factory()->create(['name' => 'Daniella']);

        if (! $this->site->isAccessibleBy($agent)) {
            $this->assignSite($agent, $this->site);
        }

        $this->lockFor($article, $agent);

        app(ArticleCompletionService::class)->complete($article->refresh(), $agent);

        return $agent;
    }

    protected function articles(int $count, string $status): void
    {
        WordpressArticle::factory()->count($count)->for($this->site, 'site')->create([
            'audit_status' => $status,
        ]);
    }
}
