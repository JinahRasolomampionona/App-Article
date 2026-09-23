<?php

namespace App\Support;

/**
 * Agents à qui un article peut être assigné.
 *
 * La liste vient de la configuration : elle n'a pas vocation à être modifiée
 * depuis l'interface, et rester en configuration évite une table et un écran
 * d'administration pour cinq noms.
 */
class AgentCatalog
{
    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        /** @var array<int, string> $agents */
        $agents = config('articleguard.agents', []);

        return array_values(array_unique(array_filter(array_map('strval', $agents))));
    }

    public static function has(?string $agent): bool
    {
        return $agent !== null && in_array($agent, self::all(), true);
    }

    /**
     * Normalise une saisie : un agent inconnu vaut « non assigné » plutôt que
     * d'être enregistré tel quel.
     */
    public static function normalize(?string $agent): ?string
    {
        $agent = trim((string) $agent);

        return self::has($agent) ? $agent : null;
    }
}
