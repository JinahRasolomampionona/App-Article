<?php

namespace Tests\Feature;

use App\Models\ArticleAuditIssue;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Statut posé à la main depuis le tableau des articles.
 *
 * L'utilisateur qui a corrigé un article ailleurs doit pouvoir le déclarer,
 * sans que cela efface la vérité de l'audit : le prochain passage rouvrira les
 * remarques encore présentes.
 */
class ArticleStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->site = WordpressSite::factory()->for($this->user)->create();
    }

    public function test_un_article_a_corriger_peut_etre_marque_corrige(): void
    {
        $article = $this->articleWithIssue();

        $this->actingAs($this->user)
            ->post(route('articles.status', $article), ['status' => WordpressArticle::AUDIT_FIXED])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $article->refresh();

        $this->assertSame(WordpressArticle::AUDIT_FIXED, $article->audit_status);
        $this->assertSame(0, $article->issues_count);
        $this->assertNotNull($article->status_set_manually_at);

        // La remarque est clôturée, donc plus affichée, mais conservée.
        $this->assertSame(0, $article->openIssues()->count());
        $this->assertSame(1, $article->issues()->count());
        $this->assertTrue($article->issues()->first()->resolved_manually);
    }

    public function test_revenir_a_corriger_rouvre_les_remarques_cloturees_manuellement(): void
    {
        $article = $this->articleWithIssue();

        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);
        $article->applyManualStatus(WordpressArticle::AUDIT_NEEDS_FIX);

        $article->refresh();

        $this->assertSame(WordpressArticle::AUDIT_NEEDS_FIX, $article->audit_status);
        $this->assertSame(1, $article->openIssues()->count());
        $this->assertSame(1, $article->issues_count);
    }

    /**
     * Une remarque réellement résolue par un audit ne doit pas être
     * ressuscitée par un simple aller-retour du sélecteur.
     */
    public function test_une_remarque_resolue_par_un_audit_n_est_pas_rouverte(): void
    {
        $article = $this->articleWithIssue();

        ArticleAuditIssue::create([
            'wordpress_article_id' => $article->id,
            'rule_type' => 'shortcode_detected',
            'severity' => 'info',
            'message' => 'Shortcode détecté',
            'detected_at' => now()->subDay(),
            'resolved_at' => now()->subHour(),
            'resolved_manually' => false,
        ]);

        $article->applyManualStatus(WordpressArticle::AUDIT_FIXED);
        $article->applyManualStatus(WordpressArticle::AUDIT_NEEDS_FIX);

        $openTypes = $article->openIssues()->pluck('rule_type')->all();

        $this->assertSame(['long_title'], $openTypes);
    }

    /**
     * « OK » signifie « aucun problème détecté » : cela relève de l'audit, pas
     * d'une déclaration de l'utilisateur.
     */
    public function test_un_article_sans_probleme_ne_peut_pas_etre_bascule_a_la_main(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_OK,
            'issues_count' => 0,
        ]);

        $this->assertFalse($article->statusIsEditable());
        $this->lockFor($article, $this->user);

        $this->actingAs($this->user)
            ->post(route('articles.status', $article), ['status' => WordpressArticle::AUDIT_FIXED])
            ->assertStatus(422);

        $this->assertSame(WordpressArticle::AUDIT_OK, $article->fresh()->audit_status);
    }

    public function test_un_statut_inconnu_est_refuse(): void
    {
        $article = $this->articleWithIssue();

        $this->actingAs($this->user)
            ->postJson(route('articles.status', $article), ['status' => 'ok'])
            ->assertStatus(422);

        $this->assertSame(WordpressArticle::AUDIT_NEEDS_FIX, $article->fresh()->audit_status);
    }

    public function test_un_autre_agent_ne_peut_pas_changer_le_statut_d_un_article_pris(): void
    {
        $article = $this->articleWithIssue();
        $intruder = User::factory()->create();
        $this->connectSite($intruder, $this->site);

        $this->actingAs($intruder)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::AUDIT_FIXED])
            ->assertStatus(409);

        $this->assertSame(WordpressArticle::AUDIT_NEEDS_FIX, $article->fresh()->audit_status);
    }

    public function test_le_tableau_affiche_un_selecteur_et_plus_de_colonne_derniere_analyse(): void
    {
        $this->articleWithIssue();

        $response = $this->actingAs($this->user)->get(route('articles.index', ['site' => $this->site->id]));

        $response->assertOk()
            ->assertSee('ag-status-select', escape: false)
            ->assertSee('À corriger')
            ->assertDontSee('Dernière analyse');
    }

    protected function articleWithIssue(): WordpressArticle
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_NEEDS_FIX,
            'issues_count' => 1,
        ]);

        ArticleAuditIssue::create([
            'wordpress_article_id' => $article->id,
            'rule_type' => 'long_title',
            'severity' => 'warning',
            'message' => 'H1 trop long (max : 20 mots)',
            'detected_at' => now(),
        ]);

        // Seul le détenteur du verrou peut modifier le statut.
        return $this->lockFor($article, $this->user);
    }
}
