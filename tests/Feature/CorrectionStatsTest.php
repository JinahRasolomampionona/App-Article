<?php

namespace Tests\Feature;

use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\Stats\CorrectionStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Répartition dans le temps des articles passés à « OK » ou « Corrigé ».
 */
class CorrectionStatsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected WordpressSite $site;

    protected CorrectionStatsService $stats;

    protected function setUp(): void
    {
        parent::setUp();

        // Un mercredi : la semaine ISO et le mois courants sont sans ambiguïté.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 10:00:00'));

        $this->user = User::factory()->create();
        $this->site = WordpressSite::factory()->for($this->user)->create(['url' => 'https://example.com']);
        $this->stats = app(CorrectionStatsService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_les_corrections_sont_reparties_par_jour(): void
    {
        $this->history('2026-09-23 08:00:00');
        $this->history('2026-09-22 08:00:00');
        $this->history('2026-09-22 20:00:00');

        $this->assertSame(1, $this->bucket('day', '2026-09-23')['articles']);
        $this->assertSame(2, $this->bucket('day', '2026-09-22')['articles']);
        $this->assertSame(0, $this->bucket('day', '2026-09-21')['articles']);
    }

    public function test_la_semaine_demarre_le_lundi(): void
    {
        // Lundi 21 et dimanche 27 appartiennent à la même semaine ISO ;
        // dimanche 20 relève de la précédente.
        $this->history('2026-09-21 08:00:00');
        $this->history('2026-09-27 23:00:00');
        $this->history('2026-09-20 08:00:00');

        $this->assertSame(2, $this->bucket('week', '2026-09-21')['articles']);
        $this->assertSame(1, $this->bucket('week', '2026-09-14')['articles']);
    }

    public function test_les_corrections_sont_regroupees_par_mois(): void
    {
        $this->history('2026-09-01 00:30:00');
        $this->history('2026-09-30 23:30:00');
        $this->history('2026-08-15 12:00:00');

        $this->assertSame(2, $this->bucket('month', '2026-09-01')['articles']);
        $this->assertSame(1, $this->bucket('month', '2026-08-01')['articles']);
    }

    public function test_les_statuts_ok_et_corriges_sont_distingues(): void
    {
        $this->history('2026-09-23 08:00:00', WordpressArticle::AUDIT_OK);
        $this->history('2026-09-23 09:00:00', WordpressArticle::AUDIT_FIXED);
        $this->history('2026-09-23 10:00:00', WordpressArticle::AUDIT_FIXED, manual: true);

        $today = $this->bucket('day', '2026-09-23');

        $this->assertSame(3, $today['articles']);
        $this->assertSame(1, $today['ok']);
        $this->assertSame(2, $today['fixed']);
        $this->assertSame(1, $today['manual']);
    }

    public function test_l_historique_d_un_autre_utilisateur_n_est_pas_compte(): void
    {
        $other = User::factory()->create();
        $otherSite = WordpressSite::factory()->for($other)->create(['url' => 'https://ailleurs.test']);

        ArticleStatusHistory::create([
            'user_id' => $other->id,
            'wordpress_site_id' => $otherSite->id,
            'site_name' => $otherSite->name,
            'status' => WordpressArticle::AUDIT_FIXED,
            'recorded_at' => CarbonImmutable::parse('2026-09-23 08:00:00'),
        ]);

        $this->history('2026-09-23 08:00:00');

        $this->assertSame(1, $this->bucket('day', '2026-09-23')['articles']);
    }

    public function test_la_serie_couvre_une_periode_continue_sans_trou(): void
    {
        $series = $this->stats->series($this->user, 'day');

        $this->assertCount(14, $series);
        $this->assertSame('2026-09-10', $series[0]['key']);
        $this->assertSame('2026-09-23', $series[13]['key']);
    }

    public function test_le_resume_compare_la_periode_courante_a_la_precedente(): void
    {
        $this->history('2026-09-22 08:00:00');
        $this->history('2026-09-22 09:00:00');

        foreach (range(1, 4) as $ignored) {
            $this->history('2026-09-23 08:00:00');
        }

        $summary = $this->stats->summary($this->user)['day'];

        $this->assertSame(4, $summary['articles']);
        $this->assertSame(2, $summary['previous_articles']);
        $this->assertSame(100, $summary['trend']);
    }

    public function test_aucune_tendance_n_est_affichee_lorsque_la_periode_precedente_est_vide(): void
    {
        $this->history('2026-09-23 08:00:00');

        $this->assertNull($this->stats->summary($this->user)['day']['trend']);
    }

    /**
     * Les tests tournent sur SQLite, la production sur MySQL : la branche
     * MySQL du regroupement ne serait jamais exécutée sans ce contrôle.
     *
     * @dataProvider mysqlBuckets
     */
    public function test_le_regroupement_mysql_ramene_au_debut_de_periode(string $granularity, string $expected): void
    {
        $method = new \ReflectionMethod(CorrectionStatsService::class, 'bucketExpression');

        $this->assertSame($expected, $method->invoke($this->stats, $granularity, 'mysql'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mysqlBuckets(): array
    {
        return [
            'jour' => ['day', 'DATE(h.recorded_at)'],
            // WEEKDAY() vaut 0 le lundi : reculer d'autant ramène au lundi.
            'semaine' => ['week', 'DATE(DATE_SUB(h.recorded_at, INTERVAL WEEKDAY(h.recorded_at) DAY))'],
            'mois' => ['month', "DATE_FORMAT(h.recorded_at, '%Y-%m-01')"],
        ];
    }

    /* --- Endpoint AJAX -------------------------------------------------------- */

    public function test_l_endpoint_renvoie_la_serie_demandee(): void
    {
        $this->history('2026-09-23 08:00:00');

        $response = $this->actingAs($this->user)
            ->getJson(route('statistics.series', ['granularity' => 'month']));

        $response->assertOk()
            ->assertJsonPath('granularity', 'month')
            ->assertJsonCount(12, 'series')
            ->assertJsonPath('series.11.key', '2026-09-01')
            ->assertJsonPath('series.11.articles', 1)
            ->assertJsonPath('summary.articles', 1);
    }

    public function test_une_granularite_inconnue_retombe_sur_le_jour(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('statistics.series', ['granularity' => 'decade']));

        $response->assertOk()->assertJsonPath('granularity', 'day');
    }

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->getJson(route('statistics.series'))->assertUnauthorized();
    }

    /* --- Utilitaires ---------------------------------------------------------- */

    protected function history(
        string $recordedAt,
        string $status = WordpressArticle::AUDIT_FIXED,
        bool $manual = false,
    ): void {
        $article = WordpressArticle::factory()->for($this->site, 'site')->create();

        ArticleStatusHistory::create([
            'user_id' => $this->user->id,
            'wordpress_site_id' => $this->site->id,
            'site_name' => $this->site->name,
            'site_url' => $this->site->url,
            'wordpress_article_id' => $article->id,
            'wp_id' => $article->wp_id,
            'article_title' => $article->title,
            'status' => $status,
            'resolved_manually' => $manual,
            'recorded_at' => CarbonImmutable::parse($recordedAt),
        ]);
    }

    /**
     * @return array{key: string, label: string, full_label: string, articles: int, ok: int, fixed: int, manual: int}
     */
    protected function bucket(string $granularity, string $key): array
    {
        $series = $this->stats->series($this->user, $granularity, 8);
        $bucket = collect($series)->firstWhere('key', $key);

        $this->assertNotNull($bucket, "Période {$key} absente de la série {$granularity}.");

        return $bucket;
    }
}
