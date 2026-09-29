<?php

namespace Tests\Feature;

use App\Models\ArticleAuditIssue;
use App\Models\ArticleNote;
use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Statut du tableau des articles : « À corriger » / « À vérifier », passés à
 * « Corrigé » à la main par l'agent assigné, puis vérification de l'Admin qui
 * peut réassigner l'article.
 */
class ArticleStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agent;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->agent = User::factory()->create(['name' => 'Jinah']);
        $this->site = WordpressSite::factory()->for($this->admin)->create();
        $this->assignSite($this->agent, $this->site);
    }

    public function test_un_article_a_corriger_peut_etre_declare_corrige_par_son_agent(): void
    {
        $article = $this->lockFor($this->articleWithIssue(), $this->agent);

        $this->actingAs($this->agent)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::STATUS_DONE])
            ->assertOk()
            ->assertJsonPath('ok', true)
            // Côté agent, la ligne quitte le tableau.
            ->assertJsonPath('removed', true);

        $article->refresh();

        $this->assertTrue($article->isCompleted());
        $this->assertSame($this->agent->id, $article->completed_by);
        $this->assertSame(WordpressArticle::AUDIT_FIXED, $article->audit_status);
        $this->assertSame(0, $article->issues_count);
        $this->assertSame('Corrigé', $article->statusLabel());

        // La remarque est clôturée, donc plus affichée, mais conservée.
        $this->assertSame(0, $article->openIssues()->count());
        $this->assertTrue($article->issues()->first()->resolved_manually);

        // L'article est libéré.
        $this->assertFalse($article->isLocked());

        // L'historique porte l'agent et la liste des erreurs corrigées.
        $history = ArticleStatusHistory::query()->sole();
        $this->assertSame($this->agent->id, $history->agent_user_id);
        $this->assertSame(1, $history->issues_resolved);
        $this->assertSame('H1 trop long (max : 20 mots)', $history->resolved_issues[0]['message']);
    }

    public function test_un_article_a_verifier_propose_a_verifier_et_corrige(): void
    {
        $article = $this->lockFor($this->articleWithoutIssue(), $this->agent);

        $this->assertSame(WordpressArticle::STATUS_TO_REVIEW, $article->displayStatus());
        $this->assertSame('À vérifier', $article->statusLabel());
        $this->assertTrue($article->statusIsEditable());
        $this->assertSame(['to_review' => 'À vérifier', 'fixed' => 'Corrigé'], $article->statusOptions());

        $this->actingAs($this->agent)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertSee('À vérifier');

        $this->actingAs($this->agent)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::STATUS_DONE])
            ->assertOk();

        $article->refresh();
        $this->assertTrue($article->isCompleted());
        // Rien à corriger : le statut d'audit n'est pas inventé.
        $this->assertSame(WordpressArticle::AUDIT_OK, $article->audit_status);
        $this->assertSame([], ArticleStatusHistory::query()->sole()->resolved_issues);
    }

    public function test_corrige_est_refuse_sans_agent_assigne(): void
    {
        $article = $this->articleWithIssue();

        $this->actingAs($this->agent)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::STATUS_DONE])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cet article n’est assigné à aucun agent : assignez-le avant de le déclarer corrigé.');

        $this->assertFalse($article->fresh()->isCompleted());
    }

    public function test_un_article_non_audite_ne_peut_pas_etre_declare_corrige(): void
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create([
            'audit_status' => WordpressArticle::AUDIT_PENDING,
        ]);
        $this->lockFor($article, $this->agent);

        $this->actingAs($this->agent)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::STATUS_DONE])
            ->assertStatus(422);

        $this->assertFalse($article->fresh()->isCompleted());
    }

    public function test_un_statut_inconnu_est_refuse(): void
    {
        $article = $this->lockFor($this->articleWithIssue(), $this->agent);

        foreach (['ok', 'needs_fix'] as $status) {
            $this->actingAs($this->agent)
                ->postJson(route('articles.status', $article), ['status' => $status])
                ->assertStatus(422);
        }

        $this->assertFalse($article->fresh()->isCompleted());
    }

    public function test_un_autre_agent_ne_peut_pas_declarer_corrige_l_article_d_un_autre(): void
    {
        $article = $this->lockFor($this->articleWithIssue(), $this->agent);
        $intruder = User::factory()->create();
        $this->assignSite($intruder, $this->site);

        $this->actingAs($intruder)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::STATUS_DONE])
            ->assertStatus(409);

        $this->assertFalse($article->fresh()->isCompleted());
    }

    public function test_l_admin_peut_declarer_corrige_au_nom_de_l_agent(): void
    {
        $article = $this->lockFor($this->articleWithIssue(), $this->agent);

        $this->actingAs($this->admin)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::STATUS_DONE])
            ->assertOk()
            ->assertJsonPath('removed', false);

        $this->assertSame($this->agent->id, $article->fresh()->completed_by);
    }

    public function test_un_article_corrige_disparait_pour_les_agents_mais_reste_chez_l_admin(): void
    {
        $article = $this->articleWithIssue(['title' => 'Guide des bagues']);
        $this->complete($article, $this->agent);

        $this->actingAs($this->agent)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertDontSee('Guide des bagues');

        // Filtre « Mes corrigés » : l'agent retrouve les siens, en lecture.
        $this->actingAs($this->agent)
            ->get(route('articles.index', ['site' => $this->site->id, 'status' => 'fixed']))
            ->assertOk()
            ->assertSee('Guide des bagues');

        // Un autre agent ne voit pas les corrections de Jinah.
        $other = User::factory()->create();
        $this->assignSite($other, $this->site);

        $this->actingAs($other)
            ->get(route('articles.index', ['site' => $this->site->id, 'status' => 'fixed']))
            ->assertOk()
            ->assertDontSee('Guide des bagues');

        $this->actingAs($this->admin)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertSee('Guide des bagues')
            ->assertSee('Réassigner');
    }

    public function test_un_agent_ne_peut_pas_reprendre_un_article_corrige(): void
    {
        $article = $this->articleWithIssue();
        $this->complete($article, $this->agent);

        $this->actingAs($this->agent)
            ->postJson(route('articles.take', $article))
            ->assertStatus(409);

        $this->actingAs($this->agent)
            ->postJson(route('articles.agent', $article), ['agent' => $this->agent->id])
            ->assertStatus(409);

        $this->assertFalse($article->fresh()->isLocked());
    }

    public function test_l_admin_reassigne_un_article_corrige_avec_un_commentaire(): void
    {
        $article = $this->articleWithIssue(['title' => 'Guide des bagues']);
        $this->complete($article, $this->agent);

        $this->actingAs($this->admin)
            ->postJson(route('articles.reassign', $article), [
                'agent' => $this->agent->id,
                'comment' => 'L’image à la une est encore floue.',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $article->refresh();

        $this->assertFalse($article->isCompleted());
        $this->assertTrue($article->isLockedBy($this->agent));
        $this->assertSame('L’image à la une est encore floue.', ArticleNote::query()->sole()->body);

        // L'article et le commentaire réapparaissent chez l'agent.
        $this->actingAs($this->agent)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertSee('Guide des bagues')
            ->assertSee('Commentaire admin');

        $this->actingAs($this->agent)
            ->get(route('articles.edit', $article))
            ->assertOk()
            ->assertSee('L’image à la une est encore floue.');
    }

    public function test_la_reassignation_et_les_commentaires_sont_reserves_a_l_admin(): void
    {
        $article = $this->articleWithIssue();
        $this->complete($article, $this->agent);

        $this->actingAs($this->agent)
            ->postJson(route('articles.reassign', $article), ['agent' => $this->agent->id])
            ->assertForbidden();

        $this->actingAs($this->agent)
            ->postJson(route('articles.notes', $article), ['comment' => 'test'])
            ->assertForbidden();

        $this->assertTrue($article->fresh()->isCompleted());
        $this->assertSame(0, ArticleNote::query()->count());
    }

    public function test_on_ne_reassigne_pas_a_un_agent_hors_du_site(): void
    {
        $article = $this->articleWithIssue();
        $this->complete($article, $this->agent);
        $stranger = User::factory()->create();

        $this->actingAs($this->admin)
            ->postJson(route('articles.reassign', $article), ['agent' => $stranger->id])
            ->assertStatus(422);

        $this->assertTrue($article->fresh()->isCompleted());
    }

    public function test_les_filtres_de_statut_suivent_le_statut_affiche(): void
    {
        $this->articleWithIssue(['title' => 'Article à corriger']);
        $this->articleWithoutIssue(['title' => 'Article à vérifier']);
        $done = $this->articleWithIssue(['title' => 'Article corrigé']);
        $this->complete($done, $this->agent);

        $see = fn (string $status) => $this->actingAs($this->admin)
            ->get(route('articles.index', ['site' => $this->site->id, 'status' => $status]))
            ->assertOk();

        $see('needs_fix')->assertSee('Article à corriger')->assertDontSee('Article à vérifier')->assertDontSee('Article corrigé');
        $see('to_review')->assertSee('Article à vérifier')->assertDontSee('Article à corriger')->assertDontSee('Article corrigé');
        $see('fixed')->assertSee('Article corrigé')->assertDontSee('Article à corriger')->assertDontSee('Article à vérifier');
    }

    /**
     * Le filtre Agent couvre tous les articles de l'agent — en cours
     * (« À corriger », « À vérifier ») et « Corrigé » —, et se combine avec
     * le filtre Statut.
     */
    public function test_le_filtre_agent_couvre_tous_les_statuts_de_l_agent(): void
    {
        $this->lockFor($this->articleWithIssue(['title' => 'Jinah à corriger']), $this->agent);
        $this->lockFor($this->articleWithoutIssue(['title' => 'Jinah à vérifier']), $this->agent);
        $this->complete($this->articleWithIssue(['title' => 'Jinah corrigé']), $this->agent);

        $daniella = User::factory()->create(['name' => 'Daniella']);
        $this->assignSite($daniella, $this->site);
        $this->lockFor($this->articleWithIssue(['title' => 'Daniella à corriger']), $daniella);
        $this->articleWithIssue(['title' => 'Article libre']);

        $list = fn (array $query) => $this->actingAs($this->admin)
            ->get(route('articles.index', ['site' => $this->site->id] + $query))
            ->assertOk();

        $list(['agent' => $this->agent->id])
            ->assertSee('Jinah à corriger')
            ->assertSee('Jinah à vérifier')
            ->assertSee('Jinah corrigé')
            ->assertDontSee('Daniella à corriger')
            ->assertDontSee('Article libre');

        $list(['agent' => $this->agent->id, 'status' => 'to_review'])
            ->assertSee('Jinah à vérifier')
            ->assertDontSee('Jinah à corriger')
            ->assertDontSee('Jinah corrigé');

        $list(['agent' => $this->agent->id, 'status' => 'fixed'])
            ->assertSee('Jinah corrigé')
            ->assertDontSee('Jinah à corriger');

        $list(['agent' => 'none'])
            ->assertSee('Article libre')
            ->assertDontSee('Jinah corrigé')
            ->assertDontSee('Daniella à corriger');

        // Libellés : le nom de l'agent seul, plus « En cours par ».
        $list([])->assertSee('>Jinah</option>', false)->assertDontSee('En cours par Jinah</option>', false);
    }

    public function test_le_tableau_affiche_un_selecteur_et_le_filtre_a_verifier(): void
    {
        $this->articleWithIssue();

        $this->actingAs($this->admin)
            ->get(route('articles.index', ['site' => $this->site->id]))
            ->assertOk()
            ->assertSee('ag-status-select', escape: false)
            ->assertSee('À corriger')
            ->assertSee('<option value="to_review"', escape: false)
            ->assertDontSee('Sans erreur')
            ->assertDontSee('Dernière analyse');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function articleWithIssue(array $attributes = []): WordpressArticle
    {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create($attributes + [
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

        return $article;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function articleWithoutIssue(array $attributes = []): WordpressArticle
    {
        return WordpressArticle::factory()->for($this->site, 'site')->create($attributes + [
            'audit_status' => WordpressArticle::AUDIT_OK,
            'issues_count' => 0,
        ]);
    }

    protected function complete(WordpressArticle $article, User $agent): void
    {
        $this->lockFor($article, $agent);

        $this->actingAs($agent)
            ->postJson(route('articles.status', $article), ['status' => WordpressArticle::STATUS_DONE])
            ->assertOk();
    }
}
