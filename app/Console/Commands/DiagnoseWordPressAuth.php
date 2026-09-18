<?php

namespace App\Console\Commands;

use App\Models\WordpressSite;
use App\Services\WordPress\WordPressApiException;
use App\Services\WordPress\WordPressApiService;
use App\Support\UnsafeUrlException;
use App\Support\UrlGuard;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Diagnostic de l'authentification REST d'un site connecté.
 *
 * Un « 401 » WordPress a plusieurs causes possibles que l'interface ne peut pas
 * toujours distinguer. Cette commande exécute les sondes une par une et indique
 * l'action corrective exacte.
 */
class DiagnoseWordPressAuth extends Command
{
    protected $signature = 'wp:diagnose {site? : Identifiant ou domaine du site (tous les sites si omis)}';

    protected $description = 'Diagnostique la connexion et l’authentification REST d’un site WordPress';

    public function handle(WordPressApiService $api, UrlGuard $guard): int
    {
        $sites = $this->resolveSites();

        if ($sites->isEmpty()) {
            $this->components->error('Aucun site WordPress à diagnostiquer.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($sites as $site) {
            $this->newLine();
            $this->components->info($site->name.' — '.$site->url);

            if (! $this->diagnose($site, $api, $guard)) {
                $failed = true;
            }
        }

        $this->newLine();

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function diagnose(WordpressSite $site, WordPressApiService $api, UrlGuard $guard): bool
    {
        // 1. URL exploitable et non interne.
        try {
            $guard->assertSafe($site->url);
            $this->ok('URL autorisée (schéma, port, destination publique)');
        } catch (UnsafeUrlException $e) {
            $this->ko('URL refusée : '.$e->getMessage());

            return false;
        }

        // 2. API REST joignable.
        try {
            $root = $api->fetchApiRoot($site);
            $this->ok('API REST joignable : '.($root['name'] ?? 'site WordPress'));
        } catch (WordPressApiException $e) {
            $this->ko($e->getMessage());
            $this->hint('Vérifiez dans un navigateur : '.$api->apiRoot($site));

            return false;
        }

        // 3. Application Passwords annoncées par le site lui-même.
        if ($api->supportsApplicationPasswords($root)) {
            $this->ok('Le site annonce le mode d’authentification « application-passwords »');
        } else {
            $this->ko('Le site n’annonce pas les Application Passwords');
            $this->hint('Elles exigent HTTPS et peuvent être désactivées par un plugin de sécurité ou par un filtre du thème.');

            return false;
        }

        // 4. Credentials enregistrés côté ArticleGuard.
        if (! $site->hasCredentials()) {
            $this->ko('Aucun identifiant enregistré : ArticleGuard reste en lecture seule');
            $this->hint('Renseignez l’identifiant WordPress et une Application Password sur la page /sites.');

            return false;
        }

        $this->ok('Identifiant enregistré : '.$site->wp_username);

        if ($site->applicationPasswordLooksLikeWordPress()) {
            $this->ok('Le secret enregistré a le format d’une Application Password (24 caractères)');
        } else {
            $this->ko('Le secret enregistré n’a pas le format WordPress (24 caractères alphanumériques)');
            $this->hint('Le mot de passe du compte wp-admin n’est jamais accepté par l’API REST. Créez une Application Password : '
                .($api->applicationPasswordAuthorizationUrl($root) ?? rtrim($site->url, '/').'/wp-admin/profile.php'));
        }

        // 5. Authentification réelle.
        try {
            $result = $api->testConnection($site);
        } catch (WordPressApiException $e) {
            $this->ko($e->getMessage());
            $this->probeAuthorizationHeader($site, $api, $e);

            return false;
        }

        if (! $result['authenticated']) {
            $this->ko('Authentification non confirmée par le site.');

            return false;
        }

        $this->ok('Authentification validée — compte WordPress : '.($result['wp_user'] ?? 'inconnu'));

        return true;
    }

    /**
     * Distingue les deux causes du code `rest_not_logged_in`.
     *
     * On envoie volontairement un identifiant inexistant avec un secret au
     * format WordPress : si l'en-tête `Authorization` parvient bien à
     * WordPress, celui-ci répond `invalid_username` ou `incorrect_password`.
     * S'il répond de nouveau `rest_not_logged_in`, l'en-tête n'a pas été vu du
     * tout — soit l'hébergeur le supprime, soit aucune Application Password
     * n'existe encore sur le site, WordPress n'activant son gestionnaire qu'à
     * partir de la première créée.
     */
    protected function probeAuthorizationHeader(WordpressSite $site, WordPressApiService $api, WordPressApiException $e): void
    {
        if ($e->wpCode() !== 'rest_not_logged_in') {
            return;
        }

        $response = Http::acceptJson()
            ->withBasicAuth('articleguard-probe-'.bin2hex(random_bytes(4)), str_repeat('a', 24))
            ->timeout((int) config('articleguard.http.timeout', 20))
            ->get($api->endpoint($site, '/users/me'), ['context' => 'edit']);

        $code = $response->json('code');

        if (in_array($code, ['invalid_username', 'incorrect_password'], true)) {
            $this->hint('L’en-tête Authorization parvient bien à WordPress : le site répond « '.$code.' » à un identifiant factice.');
            $this->hint('Le couple identifiant / Application Password enregistré est donc invalide : régénérez une Application Password.');

            return;
        }

        $this->hint('WordPress ne voit aucune tentative d’authentification. Deux causes possibles :');
        $this->line('       1. Aucune Application Password n’a encore été créée sur ce site.');
        $this->line('          WordPress → Utilisateurs → Profil → Application Passwords → « Add New ».');
        $this->line('       2. L’hébergeur supprime l’en-tête Authorization avant PHP (Apache/CGI, FastCGI).');
        $this->line('          Ajouter dans le .htaccess du site, avant les règles WordPress :');
        $this->line('            <IfModule mod_rewrite.c>');
        $this->line('              RewriteEngine On');
        $this->line('              RewriteCond %{HTTP:Authorization} ^(.*)');
        $this->line('              RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]');
        $this->line('            </IfModule>');
        $this->line('          ou activer la directive « CGIPassAuth On » côté serveur.');
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

        // Un argument numérique désigne un identifiant, et rien d'autre.
        if (is_numeric($argument)) {
            return WordpressSite::query()->whereKey((int) $argument)->get();
        }

        $host = preg_replace('#^https?://#i', '', (string) $argument);

        return WordpressSite::query()
            ->where('url', 'like', '%'.rtrim((string) $host, '/').'%')
            ->orderBy('name')
            ->get();
    }

    protected function ok(string $message): void
    {
        $this->line('   <fg=green>OK</>  '.$message);
    }

    protected function ko(string $message): void
    {
        $this->line('   <fg=red>KO</>  '.$message);
    }

    protected function hint(string $message): void
    {
        $this->line('       <fg=yellow>-></> '.$message);
    }
}
