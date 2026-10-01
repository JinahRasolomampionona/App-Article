<?php

namespace App\Console\Commands;

use App\Services\WordPress\ArticleSaveService;
use Illuminate\Console\Command;

/**
 * Exécute un « Mettre à jour » programmé par l'éditeur.
 *
 * Lancée en processus détaché par ArticleSaveService::queue() : l'éditeur
 * n'attend pas la réponse — parfois longue — de WordPress pour rendre la main.
 */
class SaveArticle extends Command
{
    protected $signature = 'articleguard:save-article {token : jeton de l’enregistrement programmé}';

    protected $description = 'Envoie à WordPress un enregistrement d’article programmé par l’éditeur';

    public function handle(ArticleSaveService $saves): int
    {
        @set_time_limit(0);

        $saves->process((string) $this->argument('token'));

        return self::SUCCESS;
    }
}
