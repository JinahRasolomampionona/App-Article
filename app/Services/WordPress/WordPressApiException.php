<?php

namespace App\Services\WordPress;

use RuntimeException;
use Throwable;

/**
 * Erreur d'échange avec l'API REST WordPress.
 *
 * `getMessage()` est destiné à l'utilisateur final : il ne contient jamais de
 * détail technique brut (« cURL error 28 »...). Les détails restent dans
 * `context` et sont écrits dans les logs Laravel.
 */
class WordPressApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $reason = 'error',
        public readonly ?int $status = null,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function unreachable(string $url, ?Throwable $previous = null): self
    {
        return new self(
            'Impossible de contacter le site WordPress. Vérifiez l’URL ou la disponibilité du site.',
            'unreachable',
            null,
            ['url' => $url, 'detail' => $previous?->getMessage()],
            $previous,
        );
    }

    /**
     * Un 401 de l'API REST WordPress recouvre des causes très différentes, et
     * seul le code d'erreur renvoyé par WordPress permet de les distinguer :
     *
     * - `rest_not_logged_in` : WordPress n'a enregistré *aucune* tentative
     *   d'authentification. Soit aucune Application Password n'a jamais été
     *   créée sur le site — WordPress n'active son gestionnaire qu'à partir de
     *   la première — soit l'hébergeur supprime l'en-tête `Authorization`
     *   avant PHP (Apache/CGI sans `CGIPassAuth`).
     * - `incorrect_password` : l'en-tête est bien arrivé, mais la valeur n'est
     *   pas une Application Password valide — typiquement le mot de passe du
     *   compte wp-admin, que l'API REST n'accepte jamais.
     * - `invalid_username` : l'identifiant ne correspond à aucun compte.
     */
    public static function unauthorized(?string $detail = null, ?string $wpCode = null): self
    {
        $message = match ($wpCode) {
            'rest_not_logged_in' => 'Le site a répondu, mais WordPress n’a pris en compte aucune authentification. Vérifiez qu’une Application Password a bien été créée dans WordPress (Utilisateurs → Profil → Application Passwords) et que l’hébergeur transmet l’en-tête Authorization à PHP.',
            'incorrect_password' => 'Application Password refusée par WordPress. Le mot de passe du compte wp-admin n’est jamais accepté par l’API REST : générez une Application Password dédiée (Utilisateurs → Profil → Application Passwords).',
            'invalid_username' => 'Identifiant WordPress inconnu sur ce site. Utilisez le nom d’utilisateur ou l’e-mail du compte propriétaire de l’Application Password.',
            'application_passwords_disabled',
            'application_passwords_disabled_for_user' => 'Les Application Passwords sont désactivées sur ce site WordPress. Elles nécessitent HTTPS et doivent être autorisées pour le compte utilisé.',
            default => 'Connexion WordPress refusée. Vérifiez l’utilisateur et l’Application Password.',
        };

        return new self(
            $message,
            'unauthorized',
            401,
            ['detail' => $detail, 'wp_code' => $wpCode],
        );
    }

    public static function forbidden(?string $detail = null, ?string $wpCode = null): self
    {
        $message = match ($wpCode) {
            'rest_forbidden_context' => 'Le compte WordPress utilisé n’a pas le droit de modifier les articles de ce site. Utilisez un compte ayant au minimum le rôle « Auteur » pour pouvoir éditer.',
            default => 'Accès refusé par WordPress. Le compte utilisé n’a pas les droits nécessaires sur cet article.',
        };

        return new self(
            $message,
            'forbidden',
            403,
            ['detail' => $detail, 'wp_code' => $wpCode],
        );
    }

    public static function notFound(?string $detail = null): self
    {
        return new self(
            'Ressource introuvable sur le site WordPress. L’article ou le média a peut-être été supprimé.',
            'not_found',
            404,
            ['detail' => $detail],
        );
    }

    /**
     * @param  array<int, string>  $wpParams  Paramètres refusés par WordPress
     */
    public static function invalidData(?string $detail = null, ?string $wpCode = null, array $wpParams = []): self
    {
        $message = $detail
            ? 'WordPress a refusé les données envoyées : '.$detail
            : 'WordPress a refusé les données envoyées. Vérifiez le contenu de l’article.';

        return new self(
            $message,
            'invalid_data',
            400,
            ['detail' => $detail, 'wp_code' => $wpCode, 'wp_params' => $wpParams],
        );
    }

    /**
     * Noms des paramètres qu'une réponse `rest_invalid_param` a refusés.
     *
     * @return array<int, string>
     */
    public function wpParams(): array
    {
        $params = $this->context['wp_params'] ?? [];

        return is_array($params) ? $params : [];
    }

    public static function rateLimited(): self
    {
        return new self(
            'Le site WordPress limite temporairement les requêtes. Réessayez dans quelques instants.',
            'rate_limited',
            429,
        );
    }

    public static function serverError(int $status, ?string $detail = null): self
    {
        return new self(
            'Le site WordPress a renvoyé une erreur serveur. Réessayez plus tard ou consultez les journaux du site.',
            'server_error',
            $status,
            ['detail' => $detail],
        );
    }

    public static function notWordPress(string $url): self
    {
        return new self(
            'L’API REST WordPress n’a pas été trouvée sur cette adresse. Vérifiez l’URL, ou que l’API REST n’est pas désactivée.',
            'not_wordpress',
            null,
            ['url' => $url],
        );
    }

    /**
     * Code d'erreur renvoyé par WordPress (`rest_not_logged_in`…) : permet de
     * proposer la bonne action corrective à l'utilisateur.
     */
    public function wpCode(): ?string
    {
        $code = $this->context['wp_code'] ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * Détails techniques destinés aux logs (jamais affichés tels quels).
     *
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return array_filter([
            'reason' => $this->reason,
            'status' => $this->status,
        ] + $this->context, fn ($value) => $value !== null);
    }
}
