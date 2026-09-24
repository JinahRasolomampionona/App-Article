import { safeUrl } from './url.js';

/**
 * Aides visuelles de l'éditeur, à la manière de l'éditeur de blocs WordPress :
 * repère du bloc courant (« H2 », « Paragraphe »…), plan des titres Hn et
 * panneau des liens.
 *
 * Rien de ce module n'écrit dans la zone éditable : les repères sont des
 * éléments superposés, pour qu'aucune classe ni balise d'interface ne se
 * retrouve dans le HTML envoyé à WordPress.
 */

const HEADINGS = 'h1,h2,h3,h4,h5,h6';
const BLOCKS = `${HEADINGS},p,li,blockquote,pre,figure,table`;

const BLOCK_LABELS = {
    p: 'Paragraphe',
    li: 'Liste',
    blockquote: 'Citation',
    pre: 'Préformaté',
    figure: 'Image',
    table: 'Tableau',
};

/** Libellé court d'un bloc : « H2 · Titre », « Paragraphe »… */
export function blockLabel(tag) {
    return /^h[1-6]$/.test(tag) ? `${tag.toUpperCase()} · Titre` : BLOCK_LABELS[tag] ?? 'Texte';
}

/** Bloc qui contient le curseur, ou `null` s'il est hors de la zone éditable. */
export function currentBlock(surface) {
    const selection = window.getSelection();
    const node = selection?.rangeCount ? selection.anchorNode : null;

    if (!node || !surface.contains(node)) return null;

    const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
    const block = element?.closest(BLOCKS);

    return block && block !== surface && surface.contains(block) ? block : null;
}

/* --- Plan des titres ------------------------------------------------------ */

/**
 * Titres d'un contenu HTML, dans l'ordre du document. Le parseur n'exécute
 * rien : le HTML n'est jamais inséré dans la page.
 */
export function readHeadings(html) {
    const doc = new DOMParser().parseFromString(`<div id="ag-wrap">${html}</div>`, 'text/html');

    return Array.from(doc.querySelectorAll(`#ag-wrap :is(${HEADINGS})`)).map((heading) => ({
        level: Number(heading.tagName[1]),
        text: heading.textContent.replace(/\s+/g, ' ').trim(),
    }));
}

/**
 * Barre « H1 : 1 · H2 : 4 · H3 : 2… » et plan dépliable, comme les extensions
 * SEO. Un clic sur un titre du plan appelle `onSelect(index)`.
 */
export function createOutline(container, { onSelect }) {
    const counts = container.querySelector('[data-outline-counts]');
    const list = container.querySelector('[data-outline-list]');
    const toggle = container.querySelector('[data-outline-toggle]');

    toggle?.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(open));
        list.hidden = !open;
    });

    list.addEventListener('click', (event) => {
        const item = event.target.closest('[data-heading-index]');
        if (item) onSelect(Number(item.dataset.headingIndex));
    });

    return {
        update(html) {
            const headings = readHeadings(html);
            const tally = [1, 2, 3, 4, 5, 6].map((level) => headings.filter((h) => h.level === level).length);

            counts.innerHTML = tally
                .map((count, i) => {
                    const level = i + 1;
                    // Le titre de l'article est déjà le H1 de la page : un H1
                    // de plus dans le contenu est une anomalie.
                    const danger = level === 1 && count > 1;
                    const classes = ['ag-hcount', count === 0 ? 'is-empty' : '', danger ? 'is-danger' : '']
                        .filter(Boolean)
                        .join(' ');

                    return `<span class="${classes}" title="${count} balise${count > 1 ? 's' : ''} H${level}">${
                        danger ? '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>' : ''
                    }H${level}<strong>${count}</strong></span>`;
                })
                .join('');

            if (toggle) {
                toggle.querySelector('[data-outline-total]').textContent = String(headings.length);
            }

            if (headings.length === 0) {
                list.innerHTML = '<li class="ag-outline__empty">Aucun titre dans le contenu.</li>';
                return;
            }

            // Le titre de l'article tient lieu de H1 : un contenu qui commence
            // par un H3 saute donc le niveau H2.
            let previous = 1;

            list.innerHTML = headings
                .map((heading, index) => {
                    const skipped = heading.level > previous + 1;
                    const warning = skipped
                        ? `Niveau sauté : H${previous} → H${heading.level}`
                        : heading.text === ''
                          ? 'Titre vide'
                          : '';
                    previous = heading.level;

                    return `
                    <li>
                        <button type="button" class="ag-outline__item" data-heading-index="${index}"
                                style="--ag-level: ${heading.level - 1}">
                            <span class="ag-outline__tag ag-outline__tag--h${heading.level}">H${heading.level}</span>
                            <span class="ag-outline__text">${escapeHtml(heading.text || '(titre vide)')}</span>
                            ${
                                warning
                                    ? `<i class="bi bi-exclamation-triangle ag-outline__warn" title="${warning}"
                                          aria-label="${warning}"></i>`
                                    : ''
                            }
                        </button>
                    </li>`;
                })
                .join('');
        },
    };
}

/* --- Repère du bloc courant ------------------------------------------------ */

/**
 * Étiquette « H2 · Titre » posée au-dessus du bloc où se trouve le curseur,
 * avec un léger cadre autour du bloc, comme dans l'éditeur WordPress.
 */
export function createBlockIndicator(root, surface) {
    const frame = document.createElement('div');
    frame.className = 'ag-block-frame';
    frame.hidden = true;

    const label = document.createElement('div');
    label.className = 'ag-block-label';
    label.hidden = true;
    label.setAttribute('aria-hidden', 'true');

    root.append(frame, label);

    function hide() {
        frame.hidden = true;
        label.hidden = true;
    }

    function show(block) {
        const box = root.getBoundingClientRect();
        const view = surface.getBoundingClientRect();
        const rect = block.getBoundingClientRect();

        // Bloc entièrement hors de la partie visible de la zone éditable.
        if (rect.bottom < view.top || rect.top > view.bottom) {
            hide();
            return;
        }

        // Le cadre est coupé aux bords de la zone défilante.
        const top = Math.max(rect.top, view.top);
        const bottom = Math.min(rect.bottom, view.bottom);

        Object.assign(frame.style, {
            top: `${top - box.top - 3}px`,
            left: `${rect.left - box.left - 6}px`,
            width: `${rect.width + 12}px`,
            height: `${bottom - top + 6}px`,
        });

        const tag = block.tagName.toLowerCase();
        label.textContent = blockLabel(tag);
        label.dataset.tag = tag;
        frame.hidden = false;
        label.hidden = false;

        // Au-dessus du bloc ; collé en haut de la zone quand le bloc défile.
        const labelTop = Math.max(top - box.top - label.offsetHeight - 4, view.top - box.top + 2);
        label.style.top = `${labelTop}px`;
        label.style.left = `${rect.left - box.left - 6}px`;
    }

    return {
        update() {
            const block = surface.hidden ? null : currentBlock(surface);
            block ? show(block) : hide();
        },
        hide,
    };
}

/* --- Panneau d'un lien ----------------------------------------------------- */

/**
 * Dans une zone éditable, le navigateur neutralise les liens. Un clic sur un
 * lien ouvre donc ce panneau : adresse cliquable (nouvel onglet), modification
 * et retrait du lien.
 */
export function createLinkPopover(root, { onChange, onError }) {
    const element = document.createElement('div');
    element.className = 'ag-link-popover';
    element.hidden = true;
    element.setAttribute('role', 'dialog');
    element.setAttribute('aria-label', 'Lien sélectionné');
    element.innerHTML = `
        <div class="ag-link-popover__head">
            <i class="bi bi-link-45deg" aria-hidden="true"></i>
            <a class="ag-link-popover__url" target="_blank" rel="noopener noreferrer" data-link-open
               title="Ouvrir dans un nouvel onglet"></a>
            <button type="button" class="ag-editor__tool" data-link="edit"
                    title="Modifier le lien" aria-label="Modifier le lien">
                <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
            <button type="button" class="ag-editor__tool" data-link="unlink"
                    title="Retirer le lien" aria-label="Retirer le lien">
                <i class="bi bi-x-circle" aria-hidden="true"></i>
            </button>
        </div>
        <div class="ag-link-popover__form" data-link-form hidden>
            <div class="input-group input-group-sm">
                <input type="url" class="form-control" placeholder="https://…" aria-label="Adresse du lien"
                       data-link-input>
                <button type="button" class="btn btn-primary" data-link="apply">Appliquer</button>
            </div>
            <div class="form-check form-check-sm mt-1 mb-0">
                <input class="form-check-input" type="checkbox" id="ag-link-blank" data-link-blank>
                <label class="form-check-label small" for="ag-link-blank">Ouvrir dans un nouvel onglet</label>
            </div>
        </div>`;

    root.append(element);

    const open = element.querySelector('[data-link-open]');
    const form = element.querySelector('[data-link-form]');
    const input = element.querySelector('[data-link-input]');
    const blank = element.querySelector('[data-link-blank]');
    let anchor = null;

    function hide() {
        anchor = null;
        element.hidden = true;
    }

    function show(target) {
        anchor = target;

        const href = target.getAttribute('href') ?? '';
        const url = safeUrl(href);
        open.textContent = href;
        open.title = href;
        if (url) {
            open.href = url;
        } else {
            open.removeAttribute('href');
        }

        input.value = href;
        blank.checked = target.getAttribute('target') === '_blank';
        form.hidden = true;
        element.hidden = false;

        const box = root.getBoundingClientRect();
        // Première ligne du lien : un lien sur deux lignes a deux rectangles.
        const rect = target.getClientRects()[0] ?? target.getBoundingClientRect();
        const width = element.offsetWidth;
        let top = rect.bottom - box.top + 6;
        if (top + element.offsetHeight + 60 > box.height) top = Math.max(8, rect.top - box.top - element.offsetHeight - 6);

        element.style.top = `${top}px`;
        element.style.left = `${Math.min(Math.max(8, rect.left - box.left), Math.max(8, box.width - width - 8))}px`;
    }

    function apply() {
        if (!anchor) return;

        const url = safeUrl(input.value);

        if (url === null) {
            onError('Lien invalide : saisissez une adresse commençant par http:// ou https://.');
            input.focus();
            return;
        }

        if (url === '') {
            unlink();
            return;
        }

        anchor.setAttribute('href', url);

        if (blank.checked) {
            anchor.setAttribute('target', '_blank');
            if (!/\bnoopener\b/.test(anchor.getAttribute('rel') ?? '')) {
                anchor.setAttribute('rel', `${anchor.getAttribute('rel') ?? ''} noopener`.trim());
            }
        } else {
            anchor.removeAttribute('target');
        }

        hide();
        onChange();
    }

    function unlink() {
        if (!anchor) return;

        // Le texte du lien reste en place.
        anchor.replaceWith(...anchor.childNodes);
        hide();
        onChange();
    }

    element.addEventListener('click', (event) => {
        const action = event.target.closest('[data-link]')?.dataset.link;

        if (action === 'edit') {
            form.hidden = !form.hidden;
            if (!form.hidden) input.focus();
        } else if (action === 'unlink') {
            unlink();
        } else if (action === 'apply') {
            apply();
        }
    });

    element.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hide();
        } else if (event.key === 'Enter' && event.target === input) {
            event.preventDefault();
            apply();
        }
    });

    return {
        element,
        show,
        hide,
        get anchor() {
            return anchor;
        },
    };
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}
