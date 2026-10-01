<?php

namespace App\Services\WordPress;

use App\Jobs\AuditArticleJob;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use App\Services\QueueHealth;
use App\Services\QueueWorkerLauncher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * « Mettre à jour » : envoi à WordPress, copie locale, audit immédiat.
 *
 * Sur certains sites, WordPress met plusieurs dizaines de secondes à
 * enregistrer un article (hooks de cache, SEO, révisions…). Pour ne pas figer
 * l'éditeur pendant ce temps, l'enregistrement part dans un processus artisan
 * détaché : la requête HTTP répond aussitôt avec un jeton, et l'éditeur suit
 * l'avancement (`status()`).
 *
 * Les enregistrements d'un même article sont sérialisés par un verrou : deux
 * « Mettre à jour » rapprochés ne peuvent pas arriver dans le désordre chez
 * WordPress.
 */
class ArticleSaveService
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected const PREFIX = 'articleguard:article-save:';

    /** Délai au-delà duquel un enregistrement jamais démarré est repris. */
    public const START_GRACE_SECONDS = 15;

    public function __construct(
        protected WordPressArticleService $articles,
        protected WordPressApiService $api,
        protected AuditService $audit,
        protected QueueWorkerLauncher $worker,
        protected QueueHealth $queue,
    ) {}

    /**
     * Enregistrement complet, dans le processus courant.
     *
     * @param  array<string, mixed>  $data  données validées par UpdateArticleRequest
     * @return array{article: WordpressArticle, changed: array<int, string>, cache: array<int, string>|null}
     *
     * @throws WordPressApiException
     */
    public function save(WordpressArticle $article, array $data): array
    {
        $writeTimeout = (int) config('articleguard.http.write_timeout', 90);

        return Cache::lock('articleguard:article-save-lock:'.$article->id, $writeTimeout + 120)
            ->block($writeTimeout + 60, function () use ($article, $data) {
                // L'enregistrement précédent a pu modifier la copie locale :
                // les différences sont calculées sur l'état le plus récent.
                $article->refresh();

                $result = app(WriteInProgress::class)->during(
                    fn () => $this->articles->update($article, $data)
                );

                $article = $result['article']->refresh();

                // Le visiteur doit voir la correction tout de suite : le cache
                // de page de l'article est vidé sur le site.
                $cache = $result['changed'] === [] ? null : $this->purgeCache($article);

                // Audit immédiat sur les règles locales pour un retour
                // instantané ; les règles réseau (images) suivent en file.
                $this->audit->run(
                    $article,
                    AuditSettings::forUser($article->site?->user),
                    allowNetwork: false,
                    trigger: 'save',
                );

                AuditArticleJob::dispatch($article, 'save');
                $this->worker->ensureRunning();

                return ['article' => $article->refresh(), 'changed' => $result['changed'], 'cache' => $cache];
            });
    }

    /**
     * Vide le cache de page de l'article sur le site. Ne fait jamais échouer
     * l'enregistrement : l'article est déjà à jour dans WordPress.
     *
     * @return array<int, string>|null caches vidés, `null` si indisponible
     */
    public function purgeCache(WordpressArticle $article): ?array
    {
        try {
            $purged = $this->api->purgePostCache($article->site, $article->wp_id);
        } catch (WordPressApiException $e) {
            Log::warning('Cache WordPress non vidé après mise à jour', [
                'site_id' => $article->wordpress_site_id,
                'wp_id' => $article->wp_id,
                'reason' => $e->reason,
            ]);

            return null;
        }

        if ($purged !== null) {
            Log::info('Cache WordPress vidé', ['article_id' => $article->id, 'caches' => $purged]);
        }

        return $purged;
    }

    /**
     * Message affiché après un enregistrement.
     *
     * @param  array{changed: array<int, string>, cache?: array<int, string>|null}  $result
     */
    public static function message(array $result): string
    {
        if ($result['changed'] === []) {
            return 'Aucune modification à envoyer : l’article était déjà à jour.';
        }

        $cache = $result['cache'] ?? null;

        return 'Article mis à jour sur WordPress.'
            .($cache ? ' Cache vidé ('.implode(', ', $cache).').' : '');
    }

    public function supportsBackground(): bool
    {
        return (bool) config('articleguard.saves.background', true) && ! $this->queue->runsInline();
    }

    /**
     * Programme l'enregistrement en arrière-plan.
     *
     * @param  array<string, mixed>  $data
     * @return string|null jeton de suivi, `null` si le processus n'a pas pu
     *                     être lancé (l'appelant enregistre alors lui-même)
     */
    public function queue(WordpressArticle $article, User $user, array $data): ?string
    {
        $token = Str::random(40);

        try {
            Cache::put($this->key($token), [
                'status' => self::STATUS_QUEUED,
                'article_id' => $article->id,
                'user_id' => $user->id,
                'data' => $data,
                'queued_at' => now()->getTimestamp(),
            ], now()->addHour());

            $this->worker->runInBackground(['articleguard:save-article', $token]);
        } catch (Throwable $e) {
            Log::warning('Enregistrement en arrière-plan impossible, envoi direct', ['detail' => $e->getMessage()]);

            try {
                Cache::forget($this->key($token));
            } catch (Throwable) {
            }

            return null;
        }

        return $token;
    }

    /**
     * Exécute un enregistrement programmé. Appelé par la commande détachée,
     * ou par le suivi si le processus n'a jamais démarré : le premier qui
     * réclame le jeton l'exécute, une seule fois.
     */
    public function process(string $token): void
    {
        $entry = $this->status($token);

        if ($entry === null || $entry['status'] !== self::STATUS_QUEUED) {
            return;
        }

        if (! Cache::add($this->key($token).':claim', true, now()->addHour())) {
            return;
        }

        $this->put($token, ['status' => self::STATUS_RUNNING, 'started_at' => now()->getTimestamp()] + $entry);

        $article = WordpressArticle::query()->find($entry['article_id']);

        if ($article === null) {
            $this->put($token, ['status' => self::STATUS_FAILED, 'message' => 'Article introuvable.', 'code' => 404] + $entry);

            return;
        }

        try {
            $result = $this->save($article, $entry['data']);

            $this->put($token, [
                'status' => self::STATUS_DONE,
                'changed' => $result['changed'],
                'message' => self::message($result),
            ] + $entry);
        } catch (WordPressApiException $e) {
            $this->put($token, [
                'status' => self::STATUS_FAILED,
                'message' => $e->getMessage(),
                'code' => $e->status && $e->status < 500 ? $e->status : 502,
            ] + $entry);
        } catch (Throwable $e) {
            Log::error('Enregistrement en arrière-plan en échec', [
                'article_id' => $article->id,
                'detail' => $e->getMessage(),
            ]);

            $this->put($token, [
                'status' => self::STATUS_FAILED,
                'message' => 'L’enregistrement a échoué. Vos modifications restent dans l’éditeur : réessayez.',
                'code' => 500,
            ] + $entry);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(string $token): ?array
    {
        try {
            $entry = Cache::get($this->key($token));
        } catch (Throwable) {
            return null;
        }

        return is_array($entry) ? $entry : null;
    }

    /**
     * Un enregistrement programmé que personne n'a démarré à temps (processus
     * détaché refusé par le système, par exemple).
     *
     * @param  array<string, mixed>  $entry
     */
    public function neverStarted(array $entry): bool
    {
        return $entry['status'] === self::STATUS_QUEUED
            && now()->getTimestamp() - (int) ($entry['queued_at'] ?? 0) >= self::START_GRACE_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function put(string $token, array $entry): void
    {
        // Le contenu n'est plus utile une fois l'enregistrement lancé.
        if ($entry['status'] !== self::STATUS_QUEUED) {
            $entry['data'] = [];
        }

        Cache::put($this->key($token), $entry, now()->addHour());
    }

    protected function key(string $token): string
    {
        return self::PREFIX.$token;
    }
}
