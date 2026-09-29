<?php

namespace Tests\Feature;

use App\Models\ArticleAudit;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page Audits, onglet « Historique des scans » : premier et dernier scan de
 * chaque article, réservé à l'Admin.
 */
class AuditHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-29 10:00:00');

        $this->admin = User::factory()->admin()->create();
        $this->site = WordpressSite::factory()->for($this->admin)->create();

        // Site courant de l'Admin.
        $this->actingAs($this->admin)->post(route('sites.select', $this->site));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_l_historique_affiche_le_premier_et_le_dernier_scan(): void
    {
        $article = $this->article('Guide des bagues');
        $this->scan($article, '2026-06-02 09:15:00', issues: 3);
        $this->scan($article, '2026-09-28 14:30:00', issues: 0);

        $this->actingAs($this->admin)
            ->get(route('audits.index', ['tab' => 'history']))
            ->assertOk()
            ->assertSee('Historique des scans par article')
            ->assertSee('Guide des bagues')
            ->assertSeeInOrder(['02/06/2026 09:15', '28/09/2026 14:30']);
    }

    public function test_le_tri_plus_ancien_met_en_tete_les_articles_jamais_ou_anciennement_scannes(): void
    {
        $recent = $this->article('Article récent');
        $this->scan($recent, '2026-09-28 10:00:00');

        $old = $this->article('Article ancien');
        $this->scan($old, '2026-05-01 10:00:00');

        $this->article('Article jamais scanné');

        $this->actingAs($this->admin)
            ->get(route('audits.index', ['tab' => 'history', 'sort' => 'oldest']))
            ->assertOk()
            ->assertSeeInOrder(['Article jamais scanné', 'Article ancien', 'Article récent']);

        $this->actingAs($this->admin)
            ->get(route('audits.index', ['tab' => 'history']))
            ->assertOk()
            ->assertSeeInOrder(['Article récent', 'Article ancien', 'Article jamais scanné']);
    }

    public function test_les_filtres_d_anciennete(): void
    {
        $this->scan($this->article('Article récent'), '2026-09-28 10:00:00');
        $this->scan($this->article('Article ancien'), '2026-05-01 10:00:00');
        $this->article('Article jamais scanné');

        $see = fn (string $period) => $this->actingAs($this->admin)
            ->get(route('audits.index', ['tab' => 'history', 'period' => $period]))
            ->assertOk();

        $see('week')->assertSee('Article récent')->assertDontSee('Article ancien')->assertDontSee('Article jamais scanné');
        $see('older')->assertSee('Article ancien')->assertDontSee('Article récent')->assertDontSee('Article jamais scanné');
        $see('never')->assertSee('Article jamais scanné')->assertDontSee('Article ancien')->assertDontSee('Article récent');
    }

    public function test_le_detail_liste_tous_les_scans_d_un_article(): void
    {
        $article = $this->article('Guide des bagues');
        $this->scan($article, '2026-06-02 09:15:00', issues: 3, trigger: 'sync');
        $this->scan($article, '2026-09-28 14:30:00', issues: 0, trigger: 'save');

        $response = $this->actingAs($this->admin)
            ->getJson(route('articles.scans', $article))
            ->assertOk()
            ->assertJsonPath('total', 2);

        $html = $response->json('html');
        $this->assertStringContainsString('28/09/2026 à 14:30', $html);
        $this->assertStringContainsString('Après mise à jour', $html);
        $this->assertStringContainsString('Synchronisation', $html);
        $this->assertStringContainsString('3 problème(s)', $html);
        $this->assertLessThan(strpos($html, '02/06/2026'), strpos($html, '28/09/2026'));
    }

    public function test_l_historique_est_reserve_a_l_admin(): void
    {
        $agent = User::factory()->create();
        $this->assignSite($agent, $this->site);
        $article = $this->article('Guide des bagues');

        $this->actingAs($agent)->get(route('audits.index', ['tab' => 'history']))->assertForbidden();
        $this->actingAs($agent)->getJson(route('articles.scans', $article))->assertForbidden();
    }

    protected function article(string $title): WordpressArticle
    {
        return WordpressArticle::factory()->for($this->site, 'site')->create(['title' => $title]);
    }

    protected function scan(WordpressArticle $article, string $at, int $issues = 0, string $trigger = 'manual'): ArticleAudit
    {
        $audit = new ArticleAudit([
            'wordpress_article_id' => $article->id,
            'status' => $issues > 0 ? WordpressArticle::AUDIT_NEEDS_FIX : WordpressArticle::AUDIT_OK,
            'issues_count' => $issues,
            'trigger_source' => $trigger,
            'completed_at' => $at,
        ]);
        $audit->created_at = $at;
        $audit->updated_at = $at;
        $audit->save();

        return $audit;
    }
}
