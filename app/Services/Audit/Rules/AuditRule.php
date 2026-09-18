<?php

namespace App\Services\Audit\Rules;

use App\Services\Audit\AuditContext;

/**
 * Contrat commun à toutes les règles d'audit.
 *
 * Ajouter une règle revient à créer une classe implémentant cette interface et
 * à l'enregistrer dans `AuditService::$rules`.
 */
interface AuditRule
{
    /**
     * Clé technique de la règle, utilisée en configuration et en base.
     */
    public function key(): string;

    /**
     * Libellé lisible, affiché dans la colonne d'audit et dans les réglages.
     */
    public function label(): string;

    /**
     * La règle nécessite-t-elle des appels réseau ?
     */
    public function requiresNetwork(): bool;

    /**
     * Types de problèmes que cette règle peut émettre.
     *
     * Sert à ne clôturer que les problèmes relevant de règles réellement
     * exécutées : désactiver une règle ne doit jamais faire croire que ses
     * problèmes ont été corrigés.
     *
     * @return array<int, string>
     */
    public function issueTypes(): array;

    /**
     * @return array<int, \App\Services\Audit\Issue>
     */
    public function evaluate(AuditContext $context): array;
}
