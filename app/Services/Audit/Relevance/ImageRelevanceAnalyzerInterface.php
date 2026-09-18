<?php

namespace App\Services\Audit\Relevance;

/**
 * Abstraction de l'analyse de cohérence image / article.
 *
 * Elle permet de brancher, plus tard, un fournisseur de vision distant sans
 * toucher au moteur d'audit. Sans fournisseur configuré, l'implémentation par
 * défaut reste locale et purement heuristique.
 */
interface ImageRelevanceAnalyzerInterface
{
    /**
     * @param  array{url: string, alt?: string, title?: string, caption?: string, surrounding_text?: string}  $image
     * @param  array{title: string, excerpt?: string, text?: string}  $article
     */
    public function analyze(array $image, array $article): RelevanceResult;

    /**
     * Le fournisseur est-il réellement utilisable (clé configurée, etc.) ?
     */
    public function isAvailable(): bool;
}
