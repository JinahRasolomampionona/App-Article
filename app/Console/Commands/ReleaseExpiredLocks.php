<?php

namespace App\Console\Commands;

use App\Services\Assignment\ArticleLockService;
use Illuminate\Console\Command;

/**
 * Clôt les prises en charge dont le verrou a expiré.
 *
 * Un verrou expiré est déjà ignoré partout (l'article est disponible) : cette
 * commande tient seulement l'historique à jour. Planifiée chaque minute.
 */
class ReleaseExpiredLocks extends Command
{
    protected $signature = 'articles:release-expired';

    protected $description = 'Libère les articles dont le verrou de traitement a expiré';

    public function handle(ArticleLockService $locks): int
    {
        $released = $locks->releaseExpired();

        $this->components->info($released === 0
            ? 'Aucun verrou expiré.'
            : $released.' article(s) libéré(s).');

        return self::SUCCESS;
    }
}
