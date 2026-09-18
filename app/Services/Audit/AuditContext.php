<?php

namespace App\Services\Audit;

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

    public function __construct(
        public readonly WordpressArticle $article,
        public readonly AuditSettings $settings,
        /**
         * Les règles réseau (téléchargement d'images) sont désactivées lorsque
         * l'audit doit rester instantané, par exemple juste après une
         * sauvegarde depuis l'éditeur.
         */
        public readonly bool $allowNetwork = true,
    ) {}

    public function html(): HtmlContent
    {
        return $this->html ??= HtmlContent::make($this->article->content);
    }

    public function title(): string
    {
        return (string) $this->article->title;
    }
}
