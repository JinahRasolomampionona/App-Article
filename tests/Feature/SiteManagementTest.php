<?php

namespace Tests\Feature;

use App\Jobs\SynchronizeSiteJob;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\Audit\AuditSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_un_site_peut_etre_connecte_et_la_synchronisation_est_programmee(): void
    {
        Queue::fake();

        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Bijouteries', 'namespaces' => ['wp/v2'], 'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/wp-admin/authorize-application.php']]]]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['id' => 1, 'name' => 'Éditeur']),
        ]);

        $this->actingAs($this->user)->post(route('sites.store'), [
            'name' => 'Bijouteries',
            'url' => 'example.com',
            'wp_username' => 'editeur',
            'application_password' => 'abcd 1234 abcd 1234 abcd',
        ])->assertRedirect(route('sites.index'));

        $site = WordpressSite::firstWhere('name', 'Bijouteries');

        $this->assertNotNull($site);
        // L'URL saisie sans protocole a été normalisée.
        $this->assertSame('https://example.com', $site->url);
        $this->assertSame(WordpressSite::STATUS_CONNECTED, $site->connection_status);

        Queue::assertPushed(SynchronizeSiteJob::class);
    }

    public function test_l_application_password_est_chiffree_en_base_et_jamais_reaffichee(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Site', 'namespaces' => ['wp/v2'], 'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/wp-admin/authorize-application.php']]]]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['id' => 1, 'name' => 'Éditeur']),
        ]);
        Queue::fake();

        $this->actingAs($this->user)->post(route('sites.store'), [
            'name' => 'Bijouteries',
            'url' => 'https://example.com',
            'wp_username' => 'editeur',
            'application_password' => 'secret-application-password',
        ]);

        $site = WordpressSite::firstWhere('name', 'Bijouteries');

        // Valeur brute en base : illisible.
        $stored = DB::table('wordpress_sites')->where('id', $site->id)->value('application_password');
        $this->assertNotSame('secret-application-password', $stored);
        $this->assertStringNotContainsString('secret-application-password', (string) $stored);

        // Déchiffrable côté application, mais les espaces ont été retirés.
        $this->assertSame('secret-application-password', $site->application_password);

        // Jamais restituée en clair dans l'interface.
        $response = $this->actingAs($this->user)->get(route('sites.edit', $site));
        $response->assertOk()->assertDontSee('secret-application-password');
        $this->assertStringContainsString('••', $site->maskedApplicationPassword());
    }

    public function test_les_espaces_de_l_application_password_sont_retires(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Site', 'namespaces' => ['wp/v2'], 'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/wp-admin/authorize-application.php']]]]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['id' => 1]),
        ]);
        Queue::fake();

        $this->actingAs($this->user)->post(route('sites.store'), [
            'name' => 'Site',
            'url' => 'https://example.com',
            'wp_username' => 'editeur',
            'application_password' => 'abcd 1234 abcd 1234 abcd 1234',
        ]);

        $this->assertSame(
            'abcd1234abcd1234abcd1234',
            WordpressSite::firstWhere('name', 'Site')->application_password
        );
    }

    public function test_une_url_interne_est_refusee_a_la_validation(): void
    {
        config(['articleguard.ssrf.allowlist' => [], 'articleguard.ssrf.allow_private' => false]);
        Http::fake();

        $this->actingAs($this->user)->post(route('sites.store'), [
            'name' => 'Interne',
            'url' => 'http://127.0.0.1:8080',
        ])->assertSessionHasErrors('url');

        $this->assertDatabaseCount('wordpress_sites', 0);
        Http::assertNothingSent();
    }

    public function test_un_protocole_non_http_est_refuse(): void
    {
        $this->actingAs($this->user)->post(route('sites.store'), [
            'name' => 'Fichier',
            'url' => 'file:///etc/passwd',
        ])->assertSessionHasErrors('url');
    }

    public function test_le_meme_site_ne_peut_pas_etre_connecte_deux_fois(): void
    {
        WordpressSite::factory()->for($this->user)->create(['url' => 'https://example.com']);

        $this->actingAs($this->user)->post(route('sites.store'), [
            'name' => 'Doublon',
            'url' => 'https://example.com/',
        ])->assertSessionHasErrors('url');
    }

    public function test_la_mise_a_jour_sans_nouveau_mot_de_passe_conserve_l_ancien(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Site', 'namespaces' => ['wp/v2'], 'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/wp-admin/authorize-application.php']]]]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['id' => 1]),
        ]);

        $site = WordpressSite::factory()->for($this->user)->create([
            'url' => 'https://example.com',
            'application_password' => 'ancien-secret',
        ]);

        $this->actingAs($this->user)->put(route('sites.update', $site), [
            'name' => 'Nouveau nom',
            'url' => 'https://example.com',
            'wp_username' => 'editeur',
            'application_password' => '',
        ])->assertRedirect(route('sites.index'));

        $site->refresh();

        $this->assertSame('Nouveau nom', $site->name);
        $this->assertSame('ancien-secret', $site->application_password);
    }

    public function test_le_test_de_connexion_met_a_jour_le_statut(): void
    {
        $site = WordpressSite::factory()->for($this->user)->create([
            'url' => 'https://example.com',
            'connection_status' => WordpressSite::STATUS_UNKNOWN,
        ]);

        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Site', 'namespaces' => ['wp/v2'], 'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/wp-admin/authorize-application.php']]]]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['id' => 1, 'name' => 'Éditeur']),
        ]);

        $this->actingAs($this->user)
            ->postJson(route('sites.test', $site))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', WordpressSite::STATUS_CONNECTED);

        $this->assertSame(WordpressSite::STATUS_CONNECTED, $site->fresh()->connection_status);
    }

    public function test_un_echec_de_connexion_renvoie_un_message_utilisateur(): void
    {
        $site = WordpressSite::factory()->for($this->user)->create(['url' => 'https://example.com']);

        Http::fake(['*' => Http::response('<html>Not found</html>', 404)]);

        $this->actingAs($this->user)
            ->postJson(route('sites.test', $site))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', WordpressSite::STATUS_UNREACHABLE);
    }

    public function test_la_suppression_d_un_site_supprime_ses_articles(): void
    {
        $site = WordpressSite::factory()->for($this->user)->create(['url' => 'https://example.com']);
        WordpressArticle::factory()->count(2)->for($site, 'site')->create();

        $this->actingAs($this->user)
            ->delete(route('sites.destroy', $site))
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseCount('wordpress_sites', 0);
        $this->assertDatabaseCount('wordpress_articles', 0);
    }

    public function test_les_parametres_d_audit_sont_enregistres_par_utilisateur(): void
    {
        $this->actingAs($this->user)->put(route('settings.update'), [
            'rules' => ['long_title' => '1', 'h1' => '1'],
            'thresholds' => [
                'title_max_words' => 14,
                'blur' => 150,
                'min_image_width' => 800,
                'min_image_height' => 600,
                'relevance' => 0.5,
            ],
        ])->assertRedirect(route('settings.edit'));

        $settings = AuditSettings::forUser($this->user->fresh());

        $this->assertTrue($settings->ruleEnabled('long_title'));
        $this->assertFalse($settings->ruleEnabled('shortcode'));
        $this->assertSame(14, $settings->threshold('title_max_words'));
        $this->assertSame(150.0, $settings->threshold('blur'));
    }
}
