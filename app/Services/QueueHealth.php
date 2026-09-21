<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Détecte une file d'attente sans consommateur.
 *
 * La synchronisation et les audits sont volontairement délégués à la file :
 * sans worker (`php artisan queue:work`), les jobs s'empilent et l'interface
 * attendrait indéfiniment des articles qui n'arriveront jamais. Plutôt que
 * d'afficher un spinner sans fin, l'application le dit.
 */
class QueueHealth
{
    /** Délai au-delà duquel un job jamais réservé trahit l'absence de worker. */
    public const GRACE_SECONDS = 20;

    /**
     * La détection n'est possible que sur le driver `database`, le seul dont
     * l'état est lisible en SQL. Les autres drivers renvoient toujours « sain »
     * faute de pouvoir conclure.
     */
    public function isObservable(): bool
    {
        return config('queue.default') === 'database';
    }

    /**
     * Le driver `sync` exécute les jobs dans la requête : rien ne peut rester
     * en attente.
     */
    public function runsInline(): bool
    {
        return config('queue.default') === 'sync';
    }

    /**
     * Nombre de jobs en attente d'exécution.
     */
    public function pending(): int
    {
        if (! $this->isObservable()) {
            return 0;
        }

        try {
            return DB::table($this->table())
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->getTimestamp())
                ->count();
        } catch (Throwable) {
            // Table absente (migrations non jouées) : on ne bloque pas l'interface.
            return 0;
        }
    }

    /**
     * Des jobs attendent-ils sans qu'aucun worker ne les prenne ?
     *
     * Un worker occupé par un job long laisse une ligne `reserved_at` non
     * nulle : ce cas n'est donc pas confondu avec une file abandonnée.
     */
    public function isStalled(int $graceSeconds = self::GRACE_SECONDS): bool
    {
        if (! $this->isObservable()) {
            return false;
        }

        try {
            $threshold = now()->getTimestamp() - $graceSeconds;

            $oldest = DB::table($this->table())
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->getTimestamp())
                ->min('created_at');

            if ($oldest === null || $oldest > $threshold) {
                return false;
            }

            // Un job réservé signale un worker vivant, simplement occupé.
            return ! DB::table($this->table())->whereNotNull('reserved_at')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * L'application relance elle-même un worker (`QueueWorkerLauncher`) :
     * une file bloquée se débloque sans intervention de l'utilisateur.
     */
    public function recoversAutomatically(): bool
    {
        return (bool) config('articleguard.queue.autostart', true) && ! $this->runsInline();
    }

    /**
     * File bloquée qui attend une action manuelle : seul cas à signaler.
     */
    public function needsManualWorker(): bool
    {
        return ! $this->recoversAutomatically() && $this->isStalled();
    }

    /**
     * Message d'aide destiné à l'interface, ou `null` si rien n'est à faire.
     */
    public function warning(): ?string
    {
        if (! $this->needsManualWorker()) {
            return null;
        }

        return 'Aucun worker ne traite la file d’attente : '.$this->pending()
            .' tâche(s) en attente. Lancez « php artisan queue:work » (ou « composer dev »), '
            .'ou synchronisez directement avec « php artisan wp:sync ».';
    }

    protected function table(): string
    {
        return (string) config('queue.connections.database.table', 'jobs');
    }
}
