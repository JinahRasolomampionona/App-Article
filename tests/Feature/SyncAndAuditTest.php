<?php

namespace Tests\Feature;

use App\Models\ArticleAuditIssue;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressCategory;
use App\Models\WordpressSite;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use App\Services\WordPress\WordPressSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeWordPress(array $posts, array $categories = [], array $media = []): void
    {
        Http::fake([
            '*/wp/v2/categories*' => Http::response($categories, 200, ['X-WP-Total' => count($categories), 'X-WP-TotalPages' => 1]),
            '*/wp/v2/media*' => Http::response($media, 200, ['X-WP-Total' => count($media), 'X-WP-TotalPages' => 1]),
            '*/wp/v2/posts*' => Http::response($posts, 200, ['X-WP-Total' => count($posts), 'X-WP-TotalPages' => 1]),
        ]);
    }

    public function test_la_synchronisation_enregistre_categories_articles_et_liaisons(): void
    {
        $site = WordpressSite::factory()->create(['url' => 'https://example.com']);

        $this->fakeWordPress(
            posts: [[
                'id' => 101,
                'slug' => 'guide-des-bagues',
                'link' => 'https://example.com/guide-des-bagues',
                'title' => ['raw' => 'Guide des bagues'],
                'content' => ['raw' => '<p>Contenu.</p>'],
                'excerpt' => ['raw' => 'Extrait.'],
                'featured_media' => 55,
                'categories' => [7],
                'status' => 'publish',
                'date_gmt' => '2025-01-10T09:00:00',
                'modified_gmt' => '2025-02-01T09:00:00',
            ]],
            categories: [['id' => 7, 'name' => 'Bagues', 'slug' => 'bagues', 'parent' => 0, 'count' => 1]],
            media: [['id' => 55, 'source_url' => 'https://example.com/bague.jpg', 'alt_text' => 'Bague']],
        );

        $result = app(WordPressSyncService::class)->syncSite($site);

        $this->assertSame(1, $result['categories']);
        $this->assertSame(1, $result['articles']);

        $article = WordpressArticle::firstWhere('wp_id', 101);

        $this->assertSame('Guide des bagues', $article->title);
        $this->assertSame('https://example.com/bague.jpg', $article->featured_media_url);
        $this->assertSame(['Bagues'], $article->categories->pluck('name')->all());
        $this->assertNotNull($site->fresh()->last_sync_at);
    }

    /**
     * WordPress n'impose aucune limite à ces champs. Un `alt` recopié depuis un
     * paragraphe entier suffisait à faire échouer l'insertion — et donc toute
     * la synchronisation du site.
     */
    public function test_les_champs_trop_longs_sont_tronques_sans_interrompre_la_synchronisation(): void
    {
        $site = WordpressSite::factory()->create(['url' => 'https://example.com']);

        $this->fakeWordPress(
            posts: [[
                'id' => 102,
                'slug' => str_repeat('a', 400),
                'link' => 'https://example.com/'.str_repeat('b', 1200),
                'title' => ['raw' => str_repeat('Titre à rallonge ', 60)],
                'content' => ['raw' => '<p>Contenu.</p>'],
                'featured_media' => 56,
                'categories' => [],
            ]],
            media: [[
                'id' => 56,
                'source_url' => 'https://example.com/photo.jpg',
                'alt_text' => str_repeat('Description très longue de l’image. ', 40),
            ]],
        );

        $result = app(WordPressSyncService::class)->syncSite($site);

        $this->assertSame(1, $result['articles']);

        $article = WordpressArticle::firstWhere('wp_id', 102);

        $this->assertLessThanOrEqual(512, mb_strlen($article->title));
        $this->assertLessThanOrEqual(191, mb_strlen($article->slug));
        $this->assertLessThanOrEqual(1024, mb_strlen($article->link));
        $this->assertLessThanOrEqual(512, mb_strlen($article->featured_media_alt));
        $this->assertStringStartsWith('Titre à rallonge', $article->title);
    }

    public function test_un_article_supprime_sur_wordpress_disparait_de_la_copie_locale(): void
    {
        $site = WordpressSite::factory()->create(['url' => 'https://example.com']);
        WordpressArticle::factory()->for($site, 'site')->create(['wp_id' => 999]);

        $this->fakeWordPress(posts: [[
            'id' => 101,
            'slug' => 'restant',
            'title' => ['raw' => 'Restant'],
            'content' => ['raw' => '<p>.</p>'],
            'featured_media' => 0,
            'categories' => [],
        ]]);

        app(WordPressSyncService::class)->syncSite($site);

        $this->assertDatabaseMissing('wordpress_articles', ['wp_id' => 999]);
        $this->assertDatabaseHas('wordpress_articles', ['wp_id' => 101]);
    }

    public function test_un_audit_persiste_les_problemes_et_marque_l_article_a_corriger(): void
    {
        $article = $this->articleWithIssues();

        app(AuditService::class)->run($article, AuditSettings::defaults(), allowNetwork: false);

        $article->refresh();

        $this->assertSame(WordpressArticle::AUDIT_NEEDS_FIX, $article->audit_status);
        $this->assertGreaterThan(0, $article->issues_count);

        $types = $article->openIssues()->pluck('rule_type')->all();

        $this->assertContains('featured_image_missing', $types);
        $this->assertContains('long_title', $types);
        $this->assertContains('multiple_h1', $types);
        $this->assertContains('shortcode_detected', $types);
    }

    public function test_un_probleme_persistant_conserve_sa_date_de_detection(): void
    {
        $article = $this->articleWithIssues();
        $audit = app(AuditService::class);

        $audit->run($article, AuditSettings::defaults(), allowNetwork: false);
        $first = $article->openIssues()->where('rule_type', 'long_title')->first();

        $audit->run($article->refresh(), AuditSettings::defaults(), allowNetwork: false);
        $second = $article->openIssues()->where('rule_type', 'long_title')->first();

        // Même enregistrement : le problème n'a pas été clôturé puis recréé.
        $this->assertSame($first->id, $second->id);
        $this->assertEquals($first->detected_at, $second->detected_at);
        $this->assertSame(1, ArticleAuditIssue::where('rule_type', 'long_title')->count());
    }

    public function test_un_probleme_corrige_est_cloture_et_le_statut_devient_corrige(): void
    {
        $article = $this->articleWithIssues();
        $audit = app(AuditService::class);

        $audit->run($article, AuditSettings::defaults(), allowNetwork: false);
        $this->assertSame(WordpressArticle::AUDIT_NEEDS_FIX, $article->refresh()->audit_status);

        // L'utilisateur corrige tout : titre court, image à la une, un seul H1,
        // une image dans le contenu et plus aucun shortcode.
        $article->forceFill([
            'title' => 'Titre court',
            'featured_media_id' => 8,
            'featured_media_url' => 'https://example.com/ok.jpg',
            'content' => '<h1>Unique</h1><h2>Section</h2><p>Texte.</p><img src="https://example.com/c.jpg" alt="Bague">',
        ])->save();

        $audit->run($article->refresh(), AuditSettings::defaults(), allowNetwork: false);
        $article->refresh();

        $this->assertSame(0, $article->issues_count);
        $this->assertSame(WordpressArticle::AUDIT_FIXED, $article->audit_status);
        $this->assertSame(0, $article->openIssues()->count());
        $this->assertGreaterThan(0, $article->issues()->whereNotNull('resolved_at')->count());
    }

    /**
     * Une règle active mais non exécutée — typiquement une règle réseau lors
     * d'un audit hors ligne — ne doit pas clôturer ses problèmes : ils seraient
     * annoncés comme corrigés sans avoir été revérifiés.
     */
    public function test_une_regle_active_mais_non_executee_ne_cloture_pas_ses_problemes(): void
    {
        $article = $this->articleWithIssues();
        $audit = app(AuditService::class);

        // Premier audit avec réseau : l'image à la une absente est détectée par
        // une règle locale, l'image cassée par une règle réseau.
        $audit->run($article, AuditSettings::defaults(), allowNetwork: false);
        $this->assertSame(1, $article->openIssues()->where('rule_type', 'long_title')->count());

        $audit->run($article->refresh(), AuditSettings::defaults(), allowNetwork: false);

        $this->assertSame(1, $article->openIssues()->where('rule_type', 'long_title')->count());
    }

    /**
     * Désactiver une règle doit faire disparaître ses remarques — sans quoi le
     * réglage n'aurait aucun effet visible — mais sans basculer l'article en
     * « Corrigé » : rien n'a été corrigé.
     */
    public function test_une_regle_desactivee_efface_ses_remarques_sans_annoncer_de_correction(): void
    {
        $article = $this->articleWithIssues();
        $audit = app(AuditService::class);

        $audit->run($article, AuditSettings::defaults(), allowNetwork: false);
        $this->assertSame(1, $article->openIssues()->where('rule_type', 'long_title')->count());

        $settings = new AuditSettings(
            array_merge(array_map('boolval', (array) config('articleguard.rules')), ['long_title' => false]),
            (array) config('articleguard.thresholds'),
        );

        $audit->run($article->refresh(), $settings, allowNetwork: false);

        // Plus aucune trace, ouverte ou clôturée.
        $this->assertSame(0, ArticleAuditIssue::where('wordpress_article_id', $article->id)
            ->where('rule_type', 'long_title')->count());

        // Les autres problèmes, eux, restent détectés.
        $this->assertSame(WordpressArticle::AUDIT_NEEDS_FIX, $article->refresh()->audit_status);
    }

    public function test_desactiver_toutes_les_regles_ne_marque_pas_l_article_corrige(): void
    {
        $article = $this->articleWithIssues();
        $audit = app(AuditService::class);

        $audit->run($article, AuditSettings::defaults(), allowNetwork: false);
        $this->assertSame(WordpressArticle::AUDIT_NEEDS_FIX, $article->refresh()->audit_status);

        $settings = new AuditSettings(
            array_fill_keys(array_keys((array) config('articleguard.rules')), false),
            (array) config('articleguard.thresholds'),
        );

        $audit->run($article->refresh(), $settings, allowNetwork: false);

        $article->refresh();

        $this->assertSame(0, $article->issues_count);
        // « OK », et non « Corrigé » : aucune correction n'a eu lieu.
        $this->assertSame(WordpressArticle::AUDIT_OK, $article->audit_status);
    }

    public function test_un_article_conforme_est_marque_ok_et_n_affiche_aucune_remarque(): void
    {
        $site = WordpressSite::factory()->create(['url' => 'https://example.com']);

        $article = WordpressArticle::factory()->for($site, 'site')->create([
            'title' => 'Titre court',
            'featured_media_id' => 4,
            'featured_media_url' => 'https://example.com/a.jpg',
            'content' => '<h1>Unique</h1><h2>Section</h2><p>Texte.</p><img src="https://example.com/b.jpg" alt="Bague">',
        ]);

        app(AuditService::class)->run($article, AuditSettings::defaults(), allowNetwork: false);
        $article->refresh();

        $this->assertSame(WordpressArticle::AUDIT_OK, $article->audit_status);
        $this->assertSame(0, $article->issues_count);
        $this->assertNull($article->statusLabel() === 'À corriger' ? 'À corriger' : null);
    }

    public function test_l_audit_est_considere_perime_quand_le_contenu_change(): void
    {
        $article = $this->articleWithIssues();

        app(AuditService::class)->run($article, AuditSettings::defaults(), allowNetwork: false);
        $this->assertFalse($article->refresh()->auditIsStale());

        $article->forceFill(['content' => '<p>Nouveau contenu.</p>'])->save();

        $this->assertTrue($article->auditIsStale());
    }

    protected function articleWithIssues(): WordpressArticle
    {
        $user = User::factory()->create();
        $site = WordpressSite::factory()->for($user)->create(['url' => 'https://example.com']);
        WordpressCategory::factory()->for($site, 'site')->create();

        return WordpressArticle::factory()->for($site, 'site')->create([
            // 24 mots : au-delà du seuil de 20 de la règle `long_title`.
            'title' => trim(str_repeat('Un titre beaucoup trop long pour le référencement ', 3)),
            'featured_media_id' => 0,
            'featured_media_url' => null,
            'content' => '<h1>Un</h1><h1>Deux</h1><p>Texte [galerie ids="1,2"] fin.</p>',
        ]);
    }
}
