<?php

namespace App\Services\WordPress;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Signale qu'un enregistrement vers WordPress est en cours.
 *
 * L'utilisateur attend la réponse de ce seul appel, alors que les audits en
 * arrière-plan téléchargent des images en pleine taille : sur une connexion
 * modeste, ils lui disputent la bande passante. Pendant une écriture, ces
 * téléchargements patientent.
 */
class WriteInProgress
{
    protected const KEY = 'articleguard:wp-write-in-progress';

    /**
     * Exécute `$callback` en signalant l'écriture pendant toute sa durée.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function during(callable $callback): mixed
    {
        $ttl = (int) config('articleguard.http.write_timeout', 90) + 60;

        try {
            Cache::put(self::KEY, true, $ttl);
        } catch (Throwable) {
            // Sans cache, les audits ne patientent simplement pas.
        }

        try {
            return $callback();
        } finally {
            try {
                Cache::forget(self::KEY);
            } catch (Throwable) {
            }
        }
    }

    public function active(): bool
    {
        try {
            return Cache::has(self::KEY);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Attend la fin de l'écriture en cours, sans jamais bloquer plus de
     * `$maxSeconds` : un signal orphelin ne doit pas figer les audits.
     */
    public function waitUntilIdle(int $maxSeconds = 90): void
    {
        $deadline = microtime(true) + $maxSeconds;

        while ($this->active() && microtime(true) < $deadline) {
            usleep(500_000);
        }
    }
}
