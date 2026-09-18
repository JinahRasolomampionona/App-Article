<?php

namespace App\Services\Audit;

use App\Models\ArticleAuditIssue;

/**
 * Résultat élémentaire produit par une règle d'audit.
 */
class Issue
{
    /**
     * @param  array<string, mixed>  $metadata  Détails exploités par l'écran
     *                                          d'édition (URL de l'image
     *                                          concernée, textes des H1...).
     */
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly string $severity = ArticleAuditIssue::SEVERITY_WARNING,
        public readonly array $metadata = [],
    ) {}

    public static function warning(string $type, string $message, array $metadata = []): self
    {
        return new self($type, $message, ArticleAuditIssue::SEVERITY_WARNING, $metadata);
    }

    public static function error(string $type, string $message, array $metadata = []): self
    {
        return new self($type, $message, ArticleAuditIssue::SEVERITY_ERROR, $metadata);
    }

    public static function info(string $type, string $message, array $metadata = []): self
    {
        return new self($type, $message, ArticleAuditIssue::SEVERITY_INFO, $metadata);
    }

    /**
     * Clé d'identité d'un problème : deux audits successifs doivent reconnaître
     * qu'il s'agit du même problème (et non le résoudre puis le recréer).
     */
    public function fingerprint(): string
    {
        $target = $this->metadata['target'] ?? $this->metadata['src'] ?? null;

        return $this->type.'|'.(is_string($target) ? $target : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }
}
