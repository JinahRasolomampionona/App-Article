<?php

namespace App\Support;

use App\Models\SiteConnection;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Comptes à qui un article peut être attribué.
 *
 * Chaque agent a son propre compte : la liste vient donc des utilisateurs
 * actifs de rôle « agent ». Elle est mémorisée pour la requête, le tableau des
 * articles la consultant pour chaque ligne.
 */
class AgentCatalog
{
    /** @var Collection<int, User>|null */
    protected static ?Collection $cache = null;

    /**
     * @return Collection<int, User>
     */
    public static function all(): Collection
    {
        return static::$cache ??= User::query()
            ->agents()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active']);
    }

    /** @var array<int, Collection<int, User>> */
    protected static array $bySite = [];

    /**
     * Agents actifs ayant connecté ce site : les seuls à qui l'un de ses
     * articles peut être attribué, puisqu'ils y travaillent avec leurs propres
     * identifiants WordPress.
     *
     * @return Collection<int, User>
     */
    public static function forSite(int $siteId): Collection
    {
        return static::$bySite[$siteId] ??= static::all()
            ->whereIn('id', SiteConnection::query()->where('wordpress_site_id', $siteId)->pluck('user_id'))
            ->values();
    }

    public static function find(int|string|null $id): ?User
    {
        if (! is_numeric($id)) {
            return null;
        }

        return static::all()->firstWhere('id', (int) $id);
    }

    /** À appeler après la création ou la modification d'un compte. */
    public static function flush(): void
    {
        static::$cache = null;
        static::$bySite = [];
    }
}
