<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Démarre à la demande un worker de file d'attente en arrière-plan.
 *
 * Sans worker permanent (poste de développement, hébergement simple), les
 * jobs de synchronisation et d'audit resteraient en attente jusqu'à un
 * `php artisan queue:work` manuel. Ce service lance un worker détaché avec
 * `--stop-when-empty` : il traite la file puis s'arrête de lui-même, sans
 * bloquer la requête HTTP qui l'a déclenché.
 */
class QueueWorkerLauncher
{
    /** Verrou évitant d'empiler des workers sur des clics rapprochés. */
    protected const LOCK_KEY = 'articleguard:queue-worker-spawn';

    /**
     * Signal de vie d'un worker, entretenu par le worker lui-même.
     *
     * Sans lui, chaque sauvegarde lançait un nouveau worker dès la fin du
     * délai de 15 s, même si le précédent traitait encore la file : plusieurs
     * workers téléchargeaient alors des images en parallèle et saturaient la
     * connexion — jusqu'à ralentir l'envoi des articles à WordPress.
     */
    public const ALIVE_KEY = 'articleguard:queue-worker-alive';

    /**
     * Durée de validité du signal : supérieure au plus long job d'audit, pour
     * qu'un worker occupé ne soit pas pris pour un worker disparu.
     */
    public const ALIVE_TTL = 240;

    public static function heartbeat(): void
    {
        try {
            Cache::put(self::ALIVE_KEY, now()->getTimestamp(), self::ALIVE_TTL);
        } catch (Throwable) {
        }
    }

    public static function stopped(): void
    {
        try {
            Cache::forget(self::ALIVE_KEY);
        } catch (Throwable) {
        }
    }

    public function workerIsAlive(): bool
    {
        try {
            return Cache::has(self::ALIVE_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    public function __construct(protected QueueHealth $queue) {}

    public function isEnabled(): bool
    {
        return (bool) config('articleguard.queue.autostart', true)
            && ! $this->queue->runsInline();
    }

    /**
     * Lance un worker si aucun n'a été démarré récemment.
     *
     * @return bool vrai si un worker vient d'être lancé
     */
    public function ensureRunning(): bool
    {
        if (! $this->isEnabled() || $this->workerIsAlive()) {
            return false;
        }

        $cooldown = max(1, (int) config('articleguard.queue.spawn_cooldown', 15));

        try {
            if (! Cache::add(self::LOCK_KEY, now()->getTimestamp(), $cooldown)) {
                return false;
            }
        } catch (Throwable) {
            // Cache indisponible : mieux vaut un worker de trop qu'aucun.
        }

        try {
            $this->spawn($this->command());
        } catch (Throwable $e) {
            Log::warning('Démarrage automatique du worker impossible', ['detail' => $e->getMessage()]);

            return false;
        }

        Log::info('Worker de file d’attente démarré automatiquement');

        return true;
    }

    /**
     * @return list<string>
     */
    protected function command(): array
    {
        return [
            $this->phpBinary(),
            base_path('artisan'),
            'queue:work',
            (string) config('queue.default'),
            '--stop-when-empty',
            '--sleep=1',
            '--max-time='.(int) config('articleguard.queue.max_time', 3600),
        ];
    }

    protected function phpBinary(): string
    {
        $configured = config('articleguard.queue.php_binary');

        if (filled($configured)) {
            return (string) $configured;
        }

        // Sous PHP-FPM, PHP_BINARY désigne php-fpm : le finder retrouve le CLI.
        return (new PhpExecutableFinder)->find(false) ?: 'php';
    }

    /**
     * Lance le processus détaché, sortie redirigée vers le néant.
     *
     * @param  list<string>  $command
     */
    protected function spawn(array $command): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // `start /B` détache réellement le worker : lancé directement par
            // proc_open, il hériterait des handles du serveur web et la
            // réponse HTTP resterait bloquée jusqu'à la fin du worker.
            $args = array_map(fn (string $arg) => '"'.str_replace('"', '', $arg).'"', $command);
            $handle = popen('start "" /B '.implode(' ', $args).' > NUL 2>&1', 'r');

            if ($handle === false) {
                throw new \RuntimeException('popen a échoué.');
            }

            pclose($handle);

            return;
        }

        $line = 'nohup '.implode(' ', array_map('escapeshellarg', $command)).' > /dev/null 2>&1 &';

        // Le shell rend la main immédiatement : le worker tourne en arrière-plan.
        $process = proc_open(['sh', '-c', $line], [], $pipes, base_path());

        if (! is_resource($process)) {
            throw new \RuntimeException('proc_open a échoué.');
        }

        proc_close($process);
    }
}
