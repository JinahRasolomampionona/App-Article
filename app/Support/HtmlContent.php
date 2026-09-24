<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Lecture et réécriture du HTML d'un article WordPress.
 *
 * Toutes les règles d'audit liées au contenu s'appuient sur cette classe, ce
 * qui garantit qu'elles voient exactement la même structure que l'éditeur.
 */
class HtmlContent
{
    protected ?DOMDocument $dom = null;

    public function __construct(
        protected string $html,
    ) {}

    public static function make(?string $html): self
    {
        return new self((string) $html);
    }

    public function raw(): string
    {
        return $this->html;
    }

    public function isEmpty(): bool
    {
        return trim(strip_tags($this->html)) === '' && ! $this->hasImages();
    }

    /**
     * Images du corps de l'article.
     *
     * Les blocs Gutenberg modernes produisent parfois des `<figure>` contenant
     * l'image : on remonte donc toutes les balises `<img>`, quel que soit leur
     * parent.
     *
     * @return array<int, array{src: string, alt: string, title: string, width: ?int, height: ?int, caption: string, in_hero: bool}>
     */
    public function images(): array
    {
        $images = [];
        $xpath = $this->xpath();

        if ($xpath === null) {
            return [];
        }

        /** @var DOMElement $node */
        foreach ($xpath->query('//img') ?: [] as $node) {
            $src = trim($node->getAttribute('src'));

            if ($src === '') {
                // Lazy-loading : l'URL réelle peut se trouver dans data-src.
                $src = trim($node->getAttribute('data-src'));
            }

            if ($src === '') {
                continue;
            }

            $images[] = [
                'src' => $src,
                'alt' => trim($node->getAttribute('alt')),
                'title' => trim($node->getAttribute('title')),
                'width' => ctype_digit($node->getAttribute('width')) ? (int) $node->getAttribute('width') : null,
                'height' => ctype_digit($node->getAttribute('height')) ? (int) $node->getAttribute('height') : null,
                'caption' => $this->captionFor($node),
                'in_hero' => $this->isInHero($node),
            ];
        }

        return $images;
    }

    public function hasImages(): bool
    {
        return (bool) preg_match('/<img[\s>]/i', $this->html);
    }

    /**
     * Textes des titres de niveau donné présents dans le contenu.
     *
     * Le titre WordPress n'est pas inclus : il est rendu par le thème, hors du
     * champ `content`.
     *
     * @return array<int, string>
     */
    public function headings(int $level): array
    {
        $xpath = $this->xpath();

        if ($xpath === null) {
            return [];
        }

        $texts = [];

        /** @var DOMElement $node */
        foreach ($xpath->query('//h'.$level) ?: [] as $node) {
            $texts[] = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
        }

        return $texts;
    }

    /**
     * Shortcodes WordPress présents dans le contenu.
     *
     * Gère `[code]`, `[code attribut="valeur"]`, `[code]contenu[/code]` et
     * `[code /]`, en ignorant les échappements `[[code]]` ainsi que les
     * fausses détections de type `[1]` ou `[...]`.
     *
     * @return array<int, array{tag: string, raw: string}>
     */
    public function shortcodes(): array
    {
        // Le contenu textuel seul : évite de confondre un attribut HTML avec un
        // shortcode.
        $pattern = '/\[(\[?)([a-zA-Z][a-zA-Z0-9_-]*)((?:[^\]\/]*(?:\/(?!\])[^\]\/]*)*?))(?:(\/)\]|\](?:(.*?)\[\/\2\])?)(\]?)/s';

        if (! preg_match_all($pattern, $this->html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $found = [];

        foreach ($matches as $match) {
            // `[[code]]` est la syntaxe d'échappement WordPress : pas un shortcode.
            if ($match[1] === '[' && ($match[6] ?? '') === ']') {
                continue;
            }

            $tag = strtolower($match[2]);

            if (in_array($tag, ['and', 'or', 'if', 'else'], true)) {
                continue;
            }

            $found[] = [
                'tag' => $tag,
                'raw' => trim(mb_substr($match[0], 0, 160)),
            ];
        }

        return $found;
    }

    /**
     * Toute occurrence de crochet dans le texte visible.
     *
     * `shortcodes()` ne reconnaît que la syntaxe WordPress canonique. Or les
     * contenus générés laissent des résidus qui n'en respectent pas la forme —
     * `[public; text...script etc]` — mais qui s'affichent tels quels aux
     * visiteurs. Un crochet dans le texte est donc signalé quelle que soit sa
     * forme.
     *
     * L'analyse porte sur le texte seul : les crochets présents dans un
     * attribut HTML ou dans du JSON embarqué ne sont pas des résidus visibles.
     *
     * @return array<int, string> Extraits dédoublonnés, dans l'ordre d'apparition
     */
    public function brackets(int $limit = 5): array
    {
        return self::bracketsIn($this->plainText(20000), $limit);
    }

    /**
     * Même détection, applicable à une chaîne quelconque (le titre notamment).
     *
     * @return array<int, string>
     */
    public static function bracketsIn(string $text, int $limit = 5): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Une paire `[...]` complète est capturée en priorité ; un crochet
        // ouvrant orphelin est signalé malgré tout.
        if (! preg_match_all('/\[[^\[\]]{0,160}\]|\[/u', $text, $matches)) {
            return [];
        }

        $found = [];

        foreach ($matches[0] as $raw) {
            $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);

            if ($raw !== '') {
                // Clé = extrait : dédoublonne sans perdre l'ordre d'apparition.
                $found[$raw] = true;
            }
        }

        return array_slice(array_keys($found), 0, $limit);
    }

    /**
     * Version assainie du contenu, sûre à injecter dans une page ArticleGuard.
     *
     * Le HTML provient d'un site tiers : il n'est jamais digne de confiance.
     * On retire donc les éléments exécutables, les gestionnaires d'événements
     * inline et les URL en `javascript:`.
     *
     * Cette version sert uniquement à l'affichage : le HTML d'origine reste
     * intact et c'est lui qui repart vers WordPress.
     */
    public function sanitized(): string
    {
        $html = preg_replace(
            '#<(script|style|object|embed|applet|meta|link|base|form)\b[^>]*>.*?</\1\s*>#is',
            '',
            $this->html
        ) ?? $this->html;

        // Balises orphelines (non refermées) des mêmes éléments.
        $html = preg_replace(
            '#<(script|style|object|embed|applet|meta|link|base|form)\b[^>]*/?>#i',
            '',
            $html
        ) ?? $html;

        // Gestionnaires d'événements inline : onclick, onerror, onload...
        $html = preg_replace(
            '#\son[a-z-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
            '',
            $html
        ) ?? $html;

        // Protocoles exécutables dans href/src/srcdoc.
        $html = preg_replace(
            '#\s(href|src|srcdoc|xlink:href)\s*=\s*("\s*(javascript|vbscript|data:text/html)[^"]*"|\'\s*(javascript|vbscript|data:text/html)[^\']*\')#i',
            ' $1="#"',
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Texte brut du contenu, utilisé pour les analyses de pertinence.
     */
    public function plainText(int $limit = 5000): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $this->html) ?? $this->html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_substr($text, 0, $limit);
    }

    /**
     * Remplace une URL d'image et, optionnellement, son attribut `alt`.
     *
     * La réécriture est faite sur la chaîne d'origine plutôt que via DOMDocument
     * afin de ne pas reformater tout le HTML de l'article (WordPress y stocke
     * des commentaires de blocs Gutenberg qu'il ne faut pas perdre).
     */
    public function replaceImage(string $currentSrc, string $newSrc, ?string $newAlt = null): string
    {
        $html = $this->html;

        $html = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match) use ($currentSrc, $newSrc, $newAlt) {
                $tag = $match[0];

                if (! str_contains($tag, $currentSrc)) {
                    return $tag;
                }

                $tag = str_replace($currentSrc, $newSrc, $tag);

                if ($newAlt !== null) {
                    $escaped = htmlspecialchars($newAlt, ENT_QUOTES, 'UTF-8');

                    $tag = preg_match('/\balt\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag)
                        ? (preg_replace('/\balt\s*=\s*("[^"]*"|\'[^\']*\')/i', 'alt="'.$escaped.'"', $tag) ?? $tag)
                        : (preg_replace('/<img\b/i', '<img alt="'.$escaped.'"', $tag, 1) ?? $tag);
                }

                return $tag;
            },
            $html
        ) ?? $html;

        // Gutenberg référence aussi l'URL dans le <a> parent et dans le
        // commentaire de bloc : on aligne le tout.
        $html = str_replace(
            ['href="'.$currentSrc.'"', "href='".$currentSrc."'"],
            ['href="'.$newSrc.'"', "href='".$newSrc."'"],
            $html
        );

        return $html;
    }

    /**
     * Supprime une image du contenu (et la `<figure>` qui l'enveloppe si elle
     * ne contient plus rien d'autre).
     */
    public function removeImage(string $src): string
    {
        $quoted = preg_quote($src, '/');

        $html = preg_replace(
            '/<figure\b[^>]*>(?:(?!<\/figure>).)*?<img\b[^>]*'.$quoted.'[^>]*>(?:(?!<\/figure>).)*?<\/figure>/is',
            '',
            $this->html
        ) ?? $this->html;

        $html = preg_replace('/<img\b[^>]*'.$quoted.'[^>]*>/i', '', $html) ?? $html;

        return $html;
    }

    /**
     * L'image appartient-elle à une section « hero » (bandeau d'en-tête) ?
     *
     * Reconnaît les classes `hero`, `*-hero`, `hero-*` et le bloc Gutenberg
     * « Couverture » (`wp-block-cover`), utilisé comme bandeau.
     */
    protected function isInHero(DOMElement $node): bool
    {
        $parent = $node;

        while ($parent instanceof DOMElement) {
            $classes = ' '.strtolower($parent->getAttribute('class').' '.$parent->getAttribute('id')).' ';

            if (preg_match('/[\s_-]hero[\s_-]|\swp-block-cover[\s_]/', $classes)) {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    protected function captionFor(DOMElement $node): string
    {
        $parent = $node->parentNode;

        while ($parent instanceof DOMElement) {
            if (strtolower($parent->nodeName) === 'figure') {
                foreach ($parent->getElementsByTagName('figcaption') as $caption) {
                    return trim((string) $caption->textContent);
                }

                break;
            }

            $parent = $parent->parentNode;
        }

        return '';
    }

    protected function xpath(): ?DOMXPath
    {
        $dom = $this->dom();

        return $dom ? new DOMXPath($dom) : null;
    }

    protected function dom(): ?DOMDocument
    {
        if ($this->dom !== null) {
            return $this->dom;
        }

        if (trim($this->html) === '') {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // Le HTML issu de WordPress est un fragment : on l'encapsule et on
        // force l'UTF-8, sinon DOMDocument suppose du Latin-1.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="ag-root">'.$this->html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        return $this->dom = $dom;
    }
}
