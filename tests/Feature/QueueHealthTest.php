<?php

namespace Tests\Feature;

use App\Services\QueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Détection d'une file d'attente sans worker.
 *
 * C'est la panne silencieuse la plus coûteuse du produit : sans worker, la
 * synchronisation reste « en cours » pour toujours et aucun article n'apparaît.
 */
class QueueHealthTest extends TestCase
{
    use RefreshDatabase;

    protected QueueHealth $health;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        $this->health = app(QueueHealth::class);
    }

    public function test_une_file_vide_est_saine(): void
    {
        $this->assertFalse($this->health->isStalled());
        $this->assertSame(0, $this->health->pending());
        $this->assertNull($this->health->warning());
    }

    public function test_un_job_recent_ne_declenche_pas_l_alerte(): void
    {
        $this->pushJob(createdSecondsAgo: 2);

        $this->assertSame(1, $this->health->pending());
        // Un worker vivant a le temps de le prendre : pas d'alerte prématurée.
        $this->assertFalse($this->health->isStalled());
    }

    public function test_un_job_jamais_reserve_declenche_l_alerte(): void
    {
        $this->pushJob(createdSecondsAgo: 120);

        $this->assertTrue($this->health->isStalled());
        $this->assertStringContainsString('queue:work', (string) $this->health->warning());
    }

    /**
     * Avec le démarrage automatique, l'application relance elle-même un
     * worker : demander une commande manuelle n'aurait plus de sens.
     */
    public function test_aucune_alerte_quand_le_worker_demarre_automatiquement(): void
    {
        config(['articleguard.queue.autostart' => true]);
        $this->pushJob(createdSecondsAgo: 120);

        $this->assertTrue($this->health->isStalled());
        $this->assertFalse($this->health->needsManualWorker());
        $this->assertNull($this->health->warning());
    }

    /**
     * Un worker occupé par un job long réserve sa ligne : ce cas ne doit pas
     * être confondu avec une file abandonnée.
     */
    public function test_un_worker_occupe_n_est_pas_signale_comme_absent(): void
    {
        $this->pushJob(createdSecondsAgo: 120);
        $this->pushJob(createdSecondsAgo: 130, reserved: true);

        $this->assertFalse($this->health->isStalled());
    }

    public function test_le_driver_sync_n_est_jamais_en_panne(): void
    {
        config(['queue.default' => 'sync']);
        $health = app(QueueHealth::class);

        $this->pushJob(createdSecondsAgo: 600);

        $this->assertTrue($health->runsInline());
        $this->assertFalse($health->isObservable());
        $this->assertFalse($health->isStalled());
    }

    protected function pushJob(int $createdSecondsAgo, bool $reserved = false): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reserved ? now()->getTimestamp() : null,
            'available_at' => now()->getTimestamp() - $createdSecondsAgo,
            'created_at' => now()->getTimestamp() - $createdSecondsAgo,
        ]);
    }
}
