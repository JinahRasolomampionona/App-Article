<?php

namespace App\Services\Audit\Relevance;

/**
 * Analyse désactivée : utilisée lorsqu'aucun fournisseur n'est configuré.
 *
 * Retourner systématiquement `unknown` garantit qu'aucune remarque n'est
 * affichée à tort quand la fonctionnalité n'est pas disponible.
 */
class NullImageRelevanceAnalyzer implements ImageRelevanceAnalyzerInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function analyze(array $image, array $article): RelevanceResult
    {
        return RelevanceResult::unknown("L'analyse de pertinence des images est désactivée.");
    }
}
