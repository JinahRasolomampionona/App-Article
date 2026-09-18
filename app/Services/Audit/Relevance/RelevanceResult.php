<?php

namespace App\Services\Audit\Relevance;

/**
 * Verdict d'une analyse de cohérence entre une image et son article.
 *
 * Il ne s'agit jamais d'une preuve : `possibly_incoherent` signale un doute à
 * lever par un humain, rien de plus. En particulier, aucune implémentation ne
 * prétend déterminer si une image a été générée par IA.
 */
class RelevanceResult
{
    public const RELEVANT = 'relevant';
    public const POSSIBLY_INCOHERENT = 'possibly_incoherent';
    public const UNKNOWN = 'unknown';

    /**
     * @param  float  $score  0 = aucun rapport constaté, 1 = correspondance forte
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $verdict,
        public readonly float $score = 0.0,
        public readonly ?string $reason = null,
        public readonly array $details = [],
    ) {}

    public static function unknown(?string $reason = null): self
    {
        return new self(self::UNKNOWN, 0.0, $reason);
    }

    public function isPossiblyIncoherent(): bool
    {
        return $this->verdict === self::POSSIBLY_INCOHERENT;
    }
}
