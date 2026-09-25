<?php

namespace App\Jobs;

use App\Models\WordpressSite;
use App\Services\WordPress\WordPressApiException;
use App\Services\WordPress\WordPressSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Synchronisation complète d'un site en arrière-plan.
 *
 * Un site de plusieurs milliers d'articles ne doit jamais bloquer une requête
 * HTTP : l'interface se contente de suivre `sync_status`.
 */
class SynchronizeSiteJob implements ShouldQueue
{
    use Queueable;

    /**
     * Un site supprimé pendant que son job attendait en file n'est pas une
     * erreur : le job est abandonné silencieusement au lieu d'être marqué en
     * échec.
     */
    public bool $deleteWhenMissingModels = true;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public WordpressSite $site,
        public bool $auditAfterwards = true,
        // Compte ayant lancé la synchronisation : ses propres identifiants
        // WordPress sont utilisés (personne n'est connecté dans un worker).
        public ?int $userId = null,
    ) {}

    public function handle(WordPressSyncService $sync): void
    {
        $this->site->useConnectionOf($this->userId);

        $this->site->forceFill([
            'sync_status' => 'running',
            'sync_message' => null,
        ])->save();

        try {
            $result = $sync->syncSite($this->site);
        } catch (WordPressApiException $e) {
            Log::warning('Synchronisation interrompue', [
                'site_id' => $this->site->id,
            ] + $e->logContext());

            $this->site->forceFill([
                'sync_status' => 'failed',
                'sync_message' => $e->getMessage(),
                'connection_status' => $this->connectionStatusFor($e),
                'last_checked_at' => now(),
            ])->save();

            return;
        }

        if ($this->auditAfterwards && $result['articles'] > 0) {
            AuditSiteJob::dispatch($this->site);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->site->forceFill([
            'sync_status' => 'failed',
            'sync_message' => 'La synchronisation a échoué. Consultez les journaux pour le détail.',
        ])->save();

        Log::error('Job de synchronisation en échec', [
            'site_id' => $this->site->id,
            'detail' => $exception->getMessage(),
        ]);
    }

    protected function connectionStatusFor(WordPressApiException $e): string
    {
        return match ($e->reason) {
            'unauthorized', 'forbidden' => WordpressSite::STATUS_AUTH_FAILED,
            'unreachable', 'not_wordpress' => WordpressSite::STATUS_UNREACHABLE,
            default => WordpressSite::STATUS_ERROR,
        };
    }
}
