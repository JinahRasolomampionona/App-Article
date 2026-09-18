<?php

namespace App\Services\WordPress;

use App\Models\WordpressSite;
use Illuminate\Support\Facades\Log;

/**
 * Test de connexion d'un site et mise à jour de son statut.
 */
class SiteConnectionService
{
    public function __construct(
        protected WordPressApiService $api,
    ) {}

    /**
     * @return array{ok: bool, status: string, message: string, details: array<string, mixed>}
     */
    public function test(WordpressSite $site): array
    {
        try {
            $result = $this->api->testConnection($site);
        } catch (WordPressApiException $e) {
            $status = match ($e->reason) {
                'unauthorized', 'forbidden' => WordpressSite::STATUS_AUTH_FAILED,
                'unreachable', 'not_wordpress', 'unsafe_url' => WordpressSite::STATUS_UNREACHABLE,
                default => WordpressSite::STATUS_ERROR,
            };

            Log::warning('Test de connexion WordPress échoué', [
                'site_id' => $site->id,
            ] + $e->logContext());

            $message = $e->getMessage();

            // Cause la plus fréquente d'un refus : le mot de passe du compte
            // wp-admin a été saisi au lieu d'une Application Password. Le
            // format généré par WordPress (24 caractères alphanumériques)
            // permet de le signaler sans jamais révéler le secret.
            if ($status === WordpressSite::STATUS_AUTH_FAILED
                && $site->hasCredentials()
                && ! $site->applicationPasswordLooksLikeWordPress()) {
                $message .= ' Le secret enregistré n’a pas le format d’une Application Password WordPress (24 caractères) : il s’agit probablement du mot de passe du compte, que l’API REST n’accepte pas.';
            }

            $site->forceFill([
                'connection_status' => $status,
                'connection_message' => $message,
                'last_checked_at' => now(),
            ])->save();

            return [
                'ok' => false,
                'status' => $status,
                'message' => $message,
                'details' => ['wp_code' => $e->wpCode()],
            ];
        }

        $message = $this->describe($site, $result);

        $site->forceFill([
            'connection_status' => WordpressSite::STATUS_CONNECTED,
            'connection_message' => $message,
            'last_checked_at' => now(),
            // Un compte authentifié n'est pas forcément autorisé : le rôle
            // détermine ce que la synchronisation pourra demander.
            'wp_can_edit' => $result['authenticated'] ? $result['can_edit'] : null,
            'wp_role' => $result['role'],
        ])->save();

        Log::info('Connexion WordPress vérifiée', [
            'site_id' => $site->id,
            'authenticated' => $result['authenticated'],
            'can_edit' => $result['can_edit'],
            'role' => $result['role'],
        ]);

        return [
            'ok' => true,
            'status' => WordpressSite::STATUS_CONNECTED,
            'message' => $message,
            'details' => $result,
        ];
    }

    /**
     * Message affiché sous le site.
     *
     * Le cas « authentifié mais sans droit d'écriture » mérite d'être dit
     * explicitement : la connexion est valide, les articles publiés seront
     * bien récupérés, mais toute tentative de mise à jour sera refusée par
     * WordPress. Annoncer « authentification validée » sans plus de détail
     * laisserait croire que l'édition est disponible.
     *
     * @param  array<string, mixed>  $result
     */
    protected function describe(WordpressSite $site, array $result): string
    {
        if (! $result['authenticated']) {
            return $site->hasCredentials()
                ? 'Site joignable, mais l’authentification n’a pas pu être confirmée.'
                : 'Site joignable en lecture seule. Ajoutez un identifiant et une Application Password pour pouvoir modifier les articles.';
        }

        $account = $result['wp_user'] ? ' (compte « '.$result['wp_user'].' »'
            .($result['role'] ? ', rôle « '.$result['role'].' »' : '').')' : '';

        if ($result['can_edit'] === false) {
            return 'Connexion établie'.$account.', mais ce compte n’a pas le droit de modifier les articles : '
                .'les articles publiés sont récupérés et audités, les modifications seront refusées par WordPress. '
                .'Utilisez un compte ayant au minimum le rôle « Auteur » pour pouvoir corriger depuis ArticleGuard.';
        }

        return 'Connexion établie et authentification WordPress validée'.$account.'.';
    }
}
