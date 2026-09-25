<?php

namespace Tests\Feature;

use App\Jobs\SynchronizeSiteJob;
use App\Models\User;
use App\Models\WordpressSite;
use App\Services\QueueHealth;
use App\Services\QueueWorkerLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Démarrage automatique du worker : l'utilisateur ne doit plus avoir à taper
 * `php artisan queue:work` pour voir ses articles arriver.
 */
class QueueWorkerLauncherTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<list<string>> */
    public static array $spawned = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$spawned = [];
        config(['queue.default' => 'database', 'articleguard.queue.autostart' => true]);

        // Aucun vrai processus n'est lancé : on enregistre la commande.
        $this->app->bind(QueueWorkerLauncher::class, fn ($app) => new class($app->make(QueueHealth::class)) extends QueueWorkerLauncher
        {
            protected function spawn(array $command): void
            {
                QueueWorkerLauncherTest::$spawned[] = $command;
            }
        });
    }

    public function test_lance_un_worker_qui_s_arrete_quand_la_file_est_vide(): void
    {
        $this->assertTrue(app(QueueWorkerLauncher::class)->ensureRunning());

        $this->assertCount(1, self::$spawned);
        $this->assertContains('queue:work', self::$spawned[0]);
        $this->assertContains('--stop-when-empty', self::$spawned[0]);
    }

    public function test_des_clics_rapproches_ne_lancent_qu_un_seul_worker(): void
    {
        $launcher = app(QueueWorkerLauncher::class);

        $launcher->ensureRunning();
        $this->assertFalse($launcher->ensureRunning());

        $this->assertCount(1, self::$spawned);
    }

    /**
     * Un worker occupé par une longue série d'audits ne doit pas être doublé :
     * plusieurs workers téléchargeant des images en parallèle saturaient la
     * connexion et ralentissaient l'envoi des articles à WordPress.
     */
    public function test_aucun_worker_supplementaire_tant_qu_un_worker_est_actif(): void
    {
        QueueWorkerLauncher::heartbeat();

        $this->assertFalse(app(QueueWorkerLauncher::class)->ensureRunning());
        $this->assertSame([], self::$spawned);
    }

    public function test_un_worker_arrete_libere_la_place(): void
    {
        QueueWorkerLauncher::heartbeat();
        event(new \Illuminate\Queue\Events\WorkerStopping(0));

        $this->assertTrue(app(QueueWorkerLauncher::class)->ensureRunning());
        $this->assertCount(1, self::$spawned);
    }

    public function test_desactivable_par_configuration(): void
    {
        config(['articleguard.queue.autostart' => false]);

        $this->assertFalse(app(QueueWorkerLauncher::class)->ensureRunning());
        $this->assertSame([], self::$spawned);
    }

    public function test_inutile_avec_le_driver_sync(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertFalse(app(QueueWorkerLauncher::class)->ensureRunning());
        $this->assertSame([], self::$spawned);
    }

    public function test_connecter_un_site_demarre_la_synchronisation_sans_commande_manuelle(): void
    {
        Queue::fake();
        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Exemple', 'namespaces' => ['wp/v2']]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['id' => 1, 'name' => 'Éditeur']),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('sites.store'), [
                'name' => 'Exemple',
                'url' => 'example.com',
                'wp_username' => 'editeur',
                'application_password' => 'abcd 1234 abcd 1234 abcd',
            ])
            ->assertRedirect(route('sites.index'));

        Queue::assertPushed(SynchronizeSiteJob::class);
        $this->assertCount(1, self::$spawned);
    }

    public function test_la_synchronisation_manuelle_demarre_un_worker(): void
    {
        Queue::fake();
        $user = User::factory()->admin()->create();
        $site = WordpressSite::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/sites/{$site->id}/sync")->assertOk();

        $this->assertCount(1, self::$spawned);
    }
}
