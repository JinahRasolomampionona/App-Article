<?php

namespace App\Services;

use App\Models\User;
use App\Models\WordpressSite;
use Illuminate\Support\Collection;

/**
 * Site WordPress actuellement sélectionné, parmi les sites de l'espace partagé.
 *
 * Le choix est mémorisé en session : passer du dashboard aux articles puis aux
 * audits conserve le même site sans le repasser dans chaque URL.
 */
class SiteContext
{
    protected const SESSION_KEY = 'articleguard.current_site';

    protected ?Collection $sites = null;

    /**
     * @return Collection<int, WordpressSite>
     */
    public function sites(?User $user = null): Collection
    {
        $user ??= auth()->user();

        if ($user === null) {
            return collect();
        }

        // Admin : tous les sites. Agent : ceux qu'il a connectés lui-même.
        return $this->sites ??= WordpressSite::query()->accessibleBy($user)->orderBy('name')->get();
    }

    public function current(?User $user = null): ?WordpressSite
    {
        $sites = $this->sites($user);

        if ($sites->isEmpty()) {
            return null;
        }

        $selectedId = session(self::SESSION_KEY);

        return $sites->firstWhere('id', $selectedId) ?? $sites->first();
    }

    public function remember(WordpressSite $site): void
    {
        session([self::SESSION_KEY => $site->id]);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Réinitialise le cache interne (utile après création/suppression).
     */
    public function refresh(): void
    {
        $this->sites = null;
    }
}
