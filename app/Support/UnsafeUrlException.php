<?php

namespace App\Support;

use RuntimeException;

/**
 * Levée lorsqu'une URL fournie par l'utilisateur ne peut pas être contactée
 * en toute sécurité (schéma interdit, hôte interne, IP privée...).
 */
class UnsafeUrlException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid',
    ) {
        parent::__construct($message);
    }

    public static function scheme(): self
    {
        return new self("L'adresse doit commencer par http:// ou https://.", 'scheme');
    }

    public static function malformed(): self
    {
        return new self("L'adresse du site n'est pas une URL valide.", 'malformed');
    }

    public static function port(int $port): self
    {
        return new self("Le port {$port} n'est pas autorisé.", 'port');
    }

    public static function unresolvable(string $host): self
    {
        return new self("Le domaine « {$host} » est introuvable (DNS).", 'dns');
    }

    public static function internal(string $host): self
    {
        return new self(
            "Le domaine « {$host} » pointe vers une adresse interne ou réservée. Pour des raisons de sécurité, ArticleGuard ne peut pas s'y connecter.",
            'internal'
        );
    }
}
