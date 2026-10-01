<?php

namespace App\Services\Audit;

use App\Models\ImageAnalysis;
use App\Models\WordpressArticle;
use App\Support\HtmlContent;

/**
 * Tout ce dont une règle a besoin pour se prononcer sur un article.
 *
 * Le HTML n'est analysé qu'une seule fois pour l'ensemble des règles.
 */
class AuditContext
{
    protected ?HtmlContent $html = null;

    /** @var array<string, true> URL déjà re-téléchargées pendant cet audit. */
    protected array $refreshed = [];

    public function __construct(
        public readonly WordpressArticle $article,
        public readonly AuditSettings $settings,
        /**
         * Les règles réseau (téléchargement d'images) sont désactivées lorsque
         * l'audit doit rester instantané, par exemple juste après une
         * sauvegarde depuis l'éditeur.
         */
        public readonly bool $allowNetwork = true,
        /**
         * Ignorer les analyses d'images mémorisées : une image corrigée sur
         * le site sans changer d'URL (fichier remplacé, image réparée) doit
         * être revue, pas jugée sur un résultat vieux de plusieurs jours.
         */
        public readonly bool $freshImages = false,
    ) {}

    public function html(): HtmlContent
    {
        return $this->html ??= HtmlContent::make($this->article->content);
    }

    public function title(): string
    {
        return (string) $this->article->title;
    }

    /**
     * Analyse d'une image selon le mode de l'audit : mémorisée seulement
     * (audit instantané), sinon téléchargée si besoin — et, en mode
     * `freshImages`, re-téléchargée une seule fois pour toutes les règles.
     */
    public function imageAnalysis(ImageQualityAnalyzer $analyzer, string $url): ?ImageAnalysis
    {
        if (! $this->allowNetwork) {
            return $analyzer->cached($url);
        }

        $force = $this->freshImages && ! isset($this->refreshed[$url]);
        $this->refreshed[$url] = true;

        return $analyzer->analyze($url, $force);
    }
}
