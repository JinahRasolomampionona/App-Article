<?php

namespace App\Services\Stats;

use App\Models\User;
use App\Models\WordpressSite;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Filtres des statistiques, normalisés et bornés par les droits du lecteur.
 *
 * C'est ici que se joue la règle « un agent ne voit que ses propres
 * statistiques » : pour un Agent, le filtre d'agent est toujours son propre
 * compte, quoi que contienne l'URL. Les services n'ont donc jamais à faire
 * confiance au paramètre `agent` transmis par le navigateur.
 */
final class StatisticsFilter
{
    public function __construct(
        public readonly User $viewer,
        public readonly ?int $siteId = null,
        /** `null` : tous · `'none'` : sans agent · entier : un compte. */
        public readonly int|string|null $agent = null,
        public readonly ?string $status = null,
        public readonly ?CarbonImmutable $from = null,
        public readonly ?CarbonImmutable $to = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $viewer = $request->user();

        $siteId = is_numeric($request->query('site'))
            ? WordpressSite::query()->accessibleBy($viewer)->whereKey((int) $request->query('site'))->value('id')
            : null;

        $status = in_array($request->query('status'), ['ok', 'fixed', 'needs_fix', 'in_progress'], true)
            ? (string) $request->query('status')
            : null;

        return new self(
            viewer: $viewer,
            siteId: $siteId,
            agent: $viewer->isAdmin() ? self::agentParam($request->query('agent')) : $viewer->id,
            status: $status,
            from: self::date($request->query('from')),
            to: self::date($request->query('to'))?->endOfDay(),
        );
    }

    /** Filtre d'un Agent : lui-même, sans autre choix possible. */
    public static function forAgent(User $agent, ?int $siteId = null): self
    {
        return new self(viewer: $agent, siteId: $siteId, agent: $agent->id);
    }

    public static function forAdmin(User $admin): self
    {
        return new self(viewer: $admin);
    }

    /**
     * Agent effectivement appliqué : un Agent est toujours restreint à son
     * compte, même si le filtre a été construit autrement.
     */
    public function agent(): int|string|null
    {
        return $this->viewer->isAdmin() ? $this->agent : $this->viewer->id;
    }

    public function agentId(): ?int
    {
        return is_int($this->agent()) ? $this->agent() : null;
    }

    public function withSite(?int $siteId): self
    {
        return new self($this->viewer, $siteId, $this->agent, $this->status, $this->from, $this->to);
    }

    /** Paramètres à reporter dans les liens (pagination, onglets). */
    public function query(array $overrides = []): array
    {
        return array_filter(array_merge([
            'site' => $this->siteId,
            'agent' => $this->viewer->isAdmin() ? $this->agent : null,
            'status' => $this->status,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
        ], $overrides), fn ($value) => $value !== null && $value !== '');
    }

    protected static function agentParam(mixed $value): int|string|null
    {
        if ($value === 'none') {
            return 'none';
        }

        return is_numeric($value) && User::query()->whereKey((int) $value)->exists() ? (int) $value : null;
    }

    protected static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
