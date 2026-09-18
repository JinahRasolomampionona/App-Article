<?php

namespace App\Services\WordPress;

/**
 * Page de résultats renvoyée par l'API WordPress, enrichie des en-têtes
 * `X-WP-Total` et `X-WP-TotalPages`.
 */
class PaginatedResult
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages,
    ) {}

    public function hasMorePages(): bool
    {
        return $this->page < $this->totalPages;
    }
}
