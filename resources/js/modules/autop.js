/**
 * Transposition de `wpautop()` de WordPress.
 *
 * Un article de l'éditeur classique est stocké sans balises de paragraphe : les
 * paragraphes sont séparés par une ligne vide, les retours à la ligne simples
 * deviennent des `<br>`. WordPress applique cette conversion à l'affichage ;
 * sans elle, l'onglet Visuel collerait tous les paragraphes les uns aux autres.
 *
 * Le contenu en blocs (Gutenberg, `<!-- wp:… -->`) porte déjà ses balises : il
 * n'est pas transformé, exactement comme sur le site.
 */

const BLOCKS =
    'table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|' +
    'address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|' +
    'figcaption|details|menu|summary|iframe|video|audio|script';

export function isBlockContent(html) {
    return /<!--\s*wp:/.test(html ?? '');
}

export function autop(html) {
    let text = String(html ?? '');

    if (text.trim() === '' || isBlockContent(text)) {
        return text;
    }

    text = text.replace(/\r\n|\r/g, '\n');

    // Le contenu des <pre> est conservé tel quel.
    const preserved = [];
    text = text.replace(/<(pre|script|style)\b[\s\S]*?<\/\1>/gi, (match) => {
        preserved.push(match);
        return `<!--ag-preserved-${preserved.length - 1}-->`;
    });

    // Un retour à la ligne à l'intérieur d'une balise n'est pas un saut de ligne.
    text = text.replace(/<[^>]*>/g, (tag) => tag.replace(/\s*\n\s*/g, ' '));

    // Les blocs sont isolés sur leurs propres lignes.
    text = text.replace(new RegExp(`(<(?:${BLOCKS})[\\s/>])`, 'gi'), '\n\n$1');
    text = text.replace(new RegExp(`(</(?:${BLOCKS})>)`, 'gi'), '$1\n\n');
    text = text.replace(/<hr\s*\/?>/gi, '<hr>\n\n');

    // Commentaires seuls sur leur ligne (<!--more-->…) : hors paragraphe.
    text = text.replace(/^[ \t]*(<!--[\s\S]*?-->)[ \t]*$/gm, '\n$1\n');

    text = text.replace(/\n\s*\n+/g, '\n\n');

    // Paragraphes : séparés par une ligne vide.
    text = text
        .split(/\n\s*\n/)
        .filter((chunk) => chunk.trim() !== '')
        .map((chunk) => `<p>${chunk.replace(/^\n+|\n+$/g, '')}</p>\n`)
        .join('');

    const block = `</?(?:${BLOCKS})[^>]*>`;

    text = text.replace(/<p>\s*<\/p>/g, '');
    text = text.replace(new RegExp(`<p>\\s*(${block})\\s*</p>`, 'gi'), '$1');
    text = text.replace(new RegExp(`<p>\\s*(${block})`, 'gi'), '$1');
    text = text.replace(new RegExp(`(${block})\\s*</p>`, 'gi'), '$1');
    text = text.replace(/<p>\s*(<!--[\s\S]*?-->)\s*<\/p>/g, '$1');

    // Retours à la ligne simples : <br>, sauf autour des blocs.
    text = text.replace(/(<br\s*\/?>)?[ \t]*\n/gi, (match, br) => (br ? `${br}\n` : '<br>\n'));
    text = text.replace(new RegExp(`(${block})\\s*<br>`, 'gi'), '$1');
    text = text.replace(
        /<br>(\s*<\/?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol|h[1-6]|table|tr|tbody|thead|blockquote|figure|figcaption)[^>]*>)/gi,
        '$1',
    );
    text = text.replace(/(<!--[\s\S]*?-->)\s*<br>/g, '$1');
    text = text.replace(/<br>\s*(<!--[\s\S]*?-->)/g, '$1');
    text = text.replace(/\n<\/p>$/g, '</p>');

    return text.replace(/<!--ag-preserved-(\d+)-->/g, (match, index) => preserved[Number(index)] ?? '');
}
