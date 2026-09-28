<?php

namespace App\Console\Commands;

use App\Models\ArticleAssignment;
use App\Models\ArticleStatusHistory;
use App\Models\WordpressArticle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remet à zéro les statistiques de tous les comptes (Admin et Agents).
 *
 * Efface l'historique des corrections et des prises en charge, et libère les
 * articles en cours. Les articles, leurs audits et les sites ne sont pas
 * touchés : l'état « OK / À corriger » d'un article reste celui mesuré par
 * l'audit.
 */
class ResetStatistics extends Command
{
    protected $signature = 'stats:reset {--force : Ne pas demander de confirmation}';

    protected $description = 'Remet à zéro les statistiques de tous les agents et de l’Admin';

    public function handle(): int
    {
        $history = ArticleStatusHistory::query()->count();
        $assignments = ArticleAssignment::query()->count();
        $locked = WordpressArticle::query()->whereNotNull('assigned_to')->count();

        $this->components->twoColumnDetail('Corrections (historique)', (string) $history);
        $this->components->twoColumnDetail('Prises en charge (historique)', (string) $assignments);
        $this->components->twoColumnDetail('Articles en cours à libérer', (string) $locked);

        if (! $this->option('force') && ! $this->confirm('Tout effacer ? Cette opération est irréversible.')) {
            $this->components->warn('Rien n’a été modifié.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            ArticleStatusHistory::query()->delete();
            ArticleAssignment::query()->delete();
            WordpressArticle::query()
                ->whereNotNull('assigned_to')
                ->update(['assigned_to' => null, 'locked_at' => null, 'lock_expires_at' => null]);
        });

        Log::warning('Statistiques remises à zéro.', compact('history', 'assignments', 'locked'));

        $this->components->info('Statistiques remises à zéro.');

        return self::SUCCESS;
    }
}
