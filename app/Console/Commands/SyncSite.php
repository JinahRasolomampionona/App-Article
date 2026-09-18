<?php

namespace App\Console\Commands;

use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use App\Services\WordPress\WordPressApiException;
use App\Services\WordPress\WordPressSyncService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Synchronisation et audit immédiats, sans passer par la file d'attente.
 *
 * Le chemin normal reste la file (`SynchronizeSiteJob` + `AuditArticleJob`),
 * qui laisse l'interface réactive. Cette commande existe pour les cas où l'on
 * veut le résultat tout de suite : première mise en route, poste de
 * développement sans worker, ou vérification après une correction.
 */
class SyncSite extends Command
{
    protected $signature = 'wp:sync
        {site? : Identifiant ou domaine du site (tous les sites si omis)}
        {--no-audit : synchroniser sans auditer}
        {--quick : audit sans les règles réseau (pas de téléchargement d’images)}
        {--all : réauditer aussi les articles déjà à jour}';

    protected $description = 'Synchronise un site WordPress et audite ses articles immédiatement';

    public function handle(WordPressSyncService $sync, AuditService $audit): int
    {
        $sites = $this->resolveSites();

        if ($sites->isEmpty()) {
            $this->components->error('Aucun site WordPress à synchroniser.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($sites as $site) {
            $this->newLine();
            $this->components->info($site->name.' — '.$site->url);

            if (! $this->synchronize($site, $sync)) {
                $failed = true;

                continue;
            }

            if (! $this->option('no-audit')) {
                $this->audit($site, $audit);
            }

            $this->summarize($site);
        }

        $this->newLine();

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function synchronize(WordpressSite $site, WordPressSyncService $sync): bool
    {
        $site->forceFill(['sync_status' => 'running', 'sync_message' => null])->save();

        $this->line('   Récupération des catégories et des articles…');

        try {
            $result = $sync->syncSite($site);
        } catch (WordPressApiException $e) {
            $site->forceFill([
                'sync_status' => 'failed',
                'sync_message' => $e->getMessage(),
            ])->save();

            $this->components->error($e->getMessage());

            return false;
        }

        $this->components->twoColumnDetail('Catégories', (string) $result['categories']);
        $this->components->twoColumnDetail('Articles synchronisés', (string) $result['articles']);

        if ($result['removed'] > 0) {
            $this->components->twoColumnDetail('Articles retirés (absents de WordPress)', (string) $result['removed']);
        }

        return true;
    }

    protected function audit(WordpressSite $site, AuditService $audit): void
    {
        $settings = AuditSettings::forUser($site->user);
        $allowNetwork = ! $this->option('quick');

        $query = $site->articles()->orderBy('id');
        $total = (int) $query->clone()->count();

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — audit en cours');
        $bar->start();

        $audited = 0;
        $skipped = 0;

        $query->chunkById(100, function (Collection $articles) use ($audit, $settings, $allowNetwork, $bar, &$audited, &$skipped) {
            foreach ($articles as $article) {
                /** @var WordpressArticle $article */
                if (! $this->option('all') && ! $article->auditIsStale()) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                try {
                    $audit->run($article, $settings, allowNetwork: $allowNetwork, trigger: 'command');
                    $audited++;
                } catch (Throwable $e) {
                    // Un article en erreur ne doit pas interrompre les autres.
                    $this->newLine();
                    $this->components->warn('Audit impossible pour #'.$article->wp_id.' : '.$e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('Articles audités', (string) $audited);

        if ($skipped > 0) {
            $this->components->twoColumnDetail('Audits déjà à jour (ignorés)', (string) $skipped.'  — « --all » pour forcer');
        }

        if (! $allowNetwork) {
            $this->components->warn('Mode --quick : les règles réseau (flou, pertinence) n’ont pas été exécutées.');
        }
    }

    protected function summarize(WordpressSite $site): void
    {
        $articles = $site->articles();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Total articles</>', (string) $articles->clone()->count());
        $this->components->twoColumnDetail('À corriger', (string) $articles->clone()->where('audit_status', WordpressArticle::AUDIT_NEEDS_FIX)->count());
        $this->components->twoColumnDetail('Sans erreur', (string) $articles->clone()->whereIn('audit_status', [WordpressArticle::AUDIT_OK, WordpressArticle::AUDIT_FIXED])->count());
        $this->components->twoColumnDetail('En attente d’audit', (string) $articles->clone()->where('audit_status', WordpressArticle::AUDIT_PENDING)->count());
        $this->components->twoColumnDetail('Problèmes ouverts', (string) $articles->clone()->sum('issues_count'));
    }

    /**
     * @return Collection<int, WordpressSite>
     */
    protected function resolveSites(): Collection
    {
        $argument = $this->argument('site');

        if (blank($argument)) {
            return WordpressSite::query()->orderBy('name')->get();
        }

        // Un argument numérique désigne un identifiant, et rien d'autre : le
        // chercher aussi dans les URL ferait correspondre « 7 » à
        // « exemple7.com » et synchroniserait le mauvais site.
        if (is_numeric($argument)) {
            return WordpressSite::query()->whereKey((int) $argument)->get();
        }

        $host = rtrim(preg_replace('#^https?://#i', '', (string) $argument) ?? '', '/');

        return WordpressSite::query()
            ->where('url', 'like', '%'.$host.'%')
            ->orderBy('name')
            ->get();
    }
}
