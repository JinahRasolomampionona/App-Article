<?php

namespace App\Console\Commands;

use App\Services\Stats\StatisticsRecorder;
use Illuminate\Console\Command;

/**
 * Reprend dans l'historique les articles déjà conformes qui n'y figurent pas.
 *
 * L'historique se remplit normalement tout seul, à chaque passage d'un article
 * à « OK » ou « Corrigé ». Cette commande sert aux cas où il a pris du retard :
 * articles importés en base directement, ou reprise après restauration.
 */
class SyncStatistics extends Command
{
    protected $signature = 'stats:sync';

    protected $description = 'Complète l’historique des statistiques à partir des articles déjà conformes';

    public function handle(StatisticsRecorder $recorder): int
    {
        $created = $recorder->backfill();

        if ($created === 0) {
            $this->components->info('Historique déjà à jour, aucune entrée ajoutée.');

            return self::SUCCESS;
        }

        $this->components->info($created.' entrée(s) ajoutée(s) à l’historique des statistiques.');

        return self::SUCCESS;
    }
}
