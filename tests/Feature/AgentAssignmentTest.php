<?php

namespace Tests\Feature;

use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressCategory;
use App\Models\WordpressSite;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assignation des articles à un agent, filtres associés et compteurs de
 * catégories recalculés d'après les filtres actifs.
 */
class AgentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 10:00:00'));
        config(['articleguard.agents' => ['Daniella', 'Niriantsoa', 'Jinah', 'Koloina', 'Miranto']]);

        $this->user = User::factory()->create();
        $this->site = WordpressSite::factory()->for($this->user)->create([
            'name' => 'bijouteries.top',
            'url' => 'https://bijouteries.top',
        ]);
        $this->site->refresh();
        session(['articleguard.current_site' => $this->site->id]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /* --- Assignation ---------------------------------------------------------- */

    public function test_un_article_peut_etre_assigne_a_un_agent(): void
    {
        $article = $this->article();

        $this->actingAs($this->user)
            ->postJson(route('articles.agent', $article), ['agent' => 'Daniella'])
            ->assertOk()
            ->assertJsonPath('agent', 'Daniella')
            ->assertJsonPath('message', 'Article assigné à Daniella.');

        $article->refresh();

        $this->assertSame('Daniella', $article->agent);
        $this->assertNotNull($article->agent_assigned_at);
    }

    public function test_l_assignation_peut_etre_retiree(): void
    {
        $article = $this->article(['agent' => 'Jinah', 'agent_assigned_at' => now()]);

        $this->actingAs($this->user)
            ->postJson(route('articles.agent', $article), ['agent' => null])
            ->assertOk()
            ->assertJsonPath('agent', null);

        $article->refresh();

        $this->assertNull($article->agent);
        $this->assertNull($article->agent_assigned_at);
    }

    public function test_un_agent_inconnu_est_refuse(): void
    {
        $article = $this->article();

        $this->actingAs($this->user)
            ->postJson(route('articles.agent', $article), ['agent' => 'Intrus'])
            ->assertStatus(422);

        $this->assertNull($article->refresh()->agent);
    }

    public function test_l_article_d_un_autre_utilisateur_ne_peut_pas_etre_assigne(): void
    {
        $other = WordpressSite::factory()->create(['url' => 'https://ailleurs.test']);
        $article = WordpressArticle::factory()->for($other, 'site')->create();

        $this->actingAs($this->user)
            ->postJson(route('articles.agent', $article), ['agent' => 'Daniella'])
            ->assertForbidden();
    }

    public function test_la_colonne_agent_est_affichee_dans_le_tableau(): void
    {
        $this->article(['agent' => 'Koloina']);

        $this->actingAs($this->user)
            ->get(route('articles.index'))
            ->assertOk()
            ->assertSee('<th scope="col">Agent</th>', false)
            ->assertSee('ag-agent-select', false)
            ->assertSee('Daniella', false)
            ->assertSee('Miranto', false);
    }

    /* --- Filtre par agent ----------------------------------------------------- */

    public function test_le_tableau_peut_etre_filtre_par_agent(): void
    {
        $this->article(['title' => 'Bagues en or', 'agent' => 'Daniella']);
        $this->article(['title' => 'Colliers fins', 'agent' => 'Jinah']);

        $html = $this->actingAs($this->user)
            ->getJson(route('articles.index', ['agent' => 'Daniella', 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->json('html');

        $this->assertStringContainsString('Bagues en or', $html);
        $this->assertStringNotContainsString('Colliers fins', $html);
    }

    public function test_le_filtre_non_assignes_isole_les_articles_sans_agent(): void
    {
        $this->article(['agent' => 'Daniella']);
        $this->article(['title' => 'Sans agent']);

        $this->actingAs($this->user)
            ->getJson(route('articles.index', ['agent' => 'none', 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_un_agent_inconnu_en_filtre_est_ignore(): void
    {
        $this->article(['agent' => 'Daniella']);
        $this->article();

        // Le filtre est écarté plutôt que de vider le tableau sans explication.
        $this->actingAs($this->user)
            ->getJson(route('articles.index', ['agent' => 'Intrus', 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    /* --- Compteurs de catégories --------------------------------------------- */

    public function test_les_compteurs_de_categories_suivent_le_filtre_de_statut(): void
    {
        [$bagues, $colliers] = $this->categories(['Bagues', 'Colliers']);

        // 10 articles dans Bagues, dont 7 à corriger.
        foreach (range(1, 10) as $index) {
            $article = $this->article([
                'audit_status' => $index <= 7
                    ? WordpressArticle::AUDIT_NEEDS_FIX
                    : WordpressArticle::AUDIT_OK,
            ]);
            $article->categories()->attach($bagues);
        }

        $this->article()->categories()->attach($colliers);

        $counts = $this->counts();
        $this->assertSame(10, $counts[$bagues->id]);

        $filtered = $this->counts(['status' => 'needs_fix']);
        $this->assertSame(7, $filtered[$bagues->id]);
        $this->assertSame(0, $filtered[$colliers->id] ?? 0);
    }

    public function test_les_compteurs_suivent_aussi_le_filtre_d_agent_et_la_recherche(): void
    {
        [$bagues] = $this->categories(['Bagues']);

        $this->article(['title' => 'Guide des bagues', 'agent' => 'Daniella'])->categories()->attach($bagues);
        $this->article(['title' => 'Autre sujet', 'agent' => 'Jinah'])->categories()->attach($bagues);

        $this->assertSame(1, $this->counts(['agent' => 'Daniella'])[$bagues->id]);
        $this->assertSame(1, $this->counts(['search' => 'Guide'])[$bagues->id]);
    }

    public function test_cocher_une_categorie_ne_remet_pas_les_autres_a_zero(): void
    {
        [$bagues, $colliers] = $this->categories(['Bagues', 'Colliers']);

        $this->article()->categories()->attach($bagues);
        $this->article()->categories()->attach($colliers);

        // En mode « au moins une », le compteur annonce ce que donnerait la
        // sélection : la catégorie cochée ne doit pas masquer les autres.
        $counts = $this->counts(['categories' => [$bagues->id], 'mode' => 'any']);

        $this->assertSame(1, $counts[$bagues->id]);
        $this->assertSame(1, $counts[$colliers->id]);
    }

    public function test_en_mode_toutes_les_categories_cochees_restent_appliquees(): void
    {
        [$bagues, $colliers] = $this->categories(['Bagues', 'Colliers']);

        $both = $this->article();
        $both->categories()->attach([$bagues->id, $colliers->id]);
        $this->article()->categories()->attach($colliers);

        // Un seul article porte Bagues : cocher « Bagues » en mode « toutes »
        // ramène Colliers à 1, puisque seul cet article resterait.
        $counts = $this->counts(['categories' => [$bagues->id], 'mode' => 'all']);

        $this->assertSame(1, $counts[$colliers->id]);
    }

    /* --- Statistiques par agent ---------------------------------------------- */

    public function test_l_agent_est_enregistre_dans_l_historique_de_correction(): void
    {
        $article = $this->article([
            'agent' => 'Daniella',
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);

        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $this->assertSame('Daniella', ArticleStatusHistory::firstOrFail()->agent);
    }

    public function test_l_historique_peut_etre_filtre_par_agent(): void
    {
        $this->corrected('Daniella', 'Bagues en or');
        $this->corrected('Jinah', 'Colliers fins');

        $response = $this->actingAs($this->user)
            ->get(route('statistics.index', ['agent' => 'Daniella']))
            ->assertOk();

        $response->assertSee('Corrections de Daniella', false)
            ->assertSee('Bagues en or', false)
            ->assertDontSee('Colliers fins', false);
    }

    public function test_la_repartition_par_agent_est_affichee(): void
    {
        $this->corrected('Daniella', 'Bagues en or');
        $this->corrected('Daniella', 'Bracelets');
        $this->corrected('Jinah', 'Colliers fins');

        $rows = app(\App\Services\Stats\ArticleStatisticsService::class)->perAgent($this->user);
        $byAgent = collect($rows)->keyBy('agent');

        $this->assertSame(2, $byAgent['Daniella']['total']);
        $this->assertSame(1, $byAgent['Jinah']['total']);
        // Un agent sans correction reste listé.
        $this->assertSame(0, $byAgent['Miranto']['total']);
    }

    /* --- Réattribution d'un article déjà conforme ----------------------------- */

    public function test_assigner_un_article_deja_ok_credite_l_agent(): void
    {
        // Article conforme avant toute assignation : son entrée d'historique
        // existe déjà, sans agent.
        $article = $this->article(['audit_status' => WordpressArticle::AUDIT_OK]);
        $this->artisan('stats:sync');

        $this->assertSame(0, $this->agentTotals()['Daniella']['ok']);

        $this->actingAs($this->user)
            ->postJson(route('articles.agent', $article), ['agent' => 'Daniella'])
            ->assertOk();

        $totals = $this->agentTotals();

        $this->assertSame(1, $totals['Daniella']['ok']);
        $this->assertSame(1, $totals['Daniella']['total']);
    }

    public function test_la_reattribution_ne_cree_pas_de_correction_supplementaire(): void
    {
        $article = $this->article(['audit_status' => WordpressArticle::AUDIT_OK]);
        $this->artisan('stats:sync');

        $this->actingAs($this->user)
            ->postJson(route('articles.agent', $article), ['agent' => 'Daniella'])
            ->assertOk();

        // L'article n'a été corrigé qu'une fois : le total général ne bouge pas.
        $this->assertSame(1, ArticleStatusHistory::count());
    }

    public function test_changer_d_agent_transfere_la_correction(): void
    {
        $article = $this->article(['audit_status' => WordpressArticle::AUDIT_OK]);
        $this->artisan('stats:sync');

        $this->actingAs($this->user)->postJson(route('articles.agent', $article), ['agent' => 'Daniella']);
        $this->actingAs($this->user)->postJson(route('articles.agent', $article), ['agent' => 'Jinah']);

        $totals = $this->agentTotals();

        $this->assertSame(0, $totals['Daniella']['total']);
        $this->assertSame(1, $totals['Jinah']['total']);
    }

    public function test_retirer_l_assignation_retire_l_attribution(): void
    {
        $article = $this->article(['audit_status' => WordpressArticle::AUDIT_OK]);
        $this->artisan('stats:sync');

        $this->actingAs($this->user)->postJson(route('articles.agent', $article), ['agent' => 'Daniella']);
        $this->actingAs($this->user)->postJson(route('articles.agent', $article), ['agent' => null]);

        $this->assertSame(0, $this->agentTotals()['Daniella']['total']);
        $this->assertNull(ArticleStatusHistory::firstOrFail()->agent);
    }

    public function test_assigner_un_article_a_corriger_ne_credite_personne(): void
    {
        $article = $this->article(['audit_status' => WordpressArticle::AUDIT_NEEDS_FIX]);

        $this->actingAs($this->user)
            ->postJson(route('articles.agent', $article), ['agent' => 'Daniella'])
            ->assertOk();

        // Rien n'est encore corrigé : l'agent sera crédité à la correction.
        $this->assertSame(0, ArticleStatusHistory::count());
        $this->assertSame(0, $this->agentTotals()['Daniella']['total']);

        $article->refresh()->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $this->assertSame(1, $this->agentTotals()['Daniella']['fixed']);
    }

    public function test_un_article_conforme_absent_de_l_historique_est_rattrape(): void
    {
        // Historique incomplet : l'assignation doit tout de même créditer.
        $article = $this->article([
            'audit_status' => WordpressArticle::AUDIT_OK,
            'last_audited_at' => CarbonImmutable::parse('2026-09-18 09:00:00'),
        ]);

        $this->assertSame(0, ArticleStatusHistory::count());

        $this->actingAs($this->user)
            ->postJson(route('articles.agent', $article), ['agent' => 'Koloina'])
            ->assertOk();

        $entry = ArticleStatusHistory::firstOrFail();

        $this->assertSame('Koloina', $entry->agent);
        // Datée de l'analyse qui l'a déclaré conforme, pas de l'assignation.
        $this->assertSame('2026-09-18', $entry->recorded_at->format('Y-m-d'));
    }

    public function test_une_correction_anterieure_d_un_autre_agent_reste_acquise(): void
    {
        $article = $this->article([
            'agent' => 'Daniella',
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);

        // Daniella corrige, l'article repart en correction, puis est reconfirmé.
        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);
        $article->refresh()->applyManualStatus(WordpressArticle::AUDIT_NEEDS_FIX);
        $article->refresh()->applyManualStatus(WordpressArticle::AUDIT_FIXED);

        $this->actingAs($this->user)->postJson(route('articles.agent', $article), ['agent' => 'Jinah']);

        $totals = $this->agentTotals();

        // Seule la dernière entrée change de titulaire.
        $this->assertSame(1, $totals['Daniella']['total']);
        $this->assertSame(1, $totals['Jinah']['total']);
    }

    public function test_les_corrections_sans_agent_sont_filtrables_a_part(): void
    {
        $this->corrected(null, 'Sans agent');
        $this->corrected('Daniella', 'Avec agent');

        $this->actingAs($this->user)
            ->get(route('statistics.index', ['agent' => 'none']))
            ->assertOk()
            ->assertSee('Corrections non assignées', false)
            ->assertSee('Sans agent', false)
            ->assertDontSee('Avec agent', false);
    }

    /* --- Utilitaires ---------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function article(array $attributes = []): WordpressArticle
    {
        return WordpressArticle::factory()->for($this->site, 'site')->create($attributes);
    }

    protected function corrected(?string $agent, string $title): void
    {
        $article = $this->article([
            'title' => $title,
            'agent' => $agent,
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
        ]);

        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, WordpressCategory>
     */
    protected function categories(array $names): array
    {
        return array_map(
            fn (string $name) => WordpressCategory::factory()->for($this->site, 'site')->create(['name' => $name]),
            $names,
        );
    }

    /**
     * Répartition par agent, indexée par nom.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function agentTotals(): array
    {
        return collect(app(\App\Services\Stats\ArticleStatisticsService::class)->perAgent($this->user))
            ->keyBy('agent')
            ->all();
    }

    /**
     * Compteurs renvoyés par le tableau pour les filtres donnés.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, int>
     */
    protected function counts(array $query = []): array
    {
        return $this->actingAs($this->user)
            ->getJson(route('articles.index', $query + ['partial' => 1]))
            ->assertOk()
            ->json('category_counts');
    }
}
