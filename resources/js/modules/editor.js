import { http } from './http.js';
import { notify } from './toast.js';
import { busy } from './busy.js';
import { createMediaPicker } from './media-picker.js';
import { sanitizeHtml } from './sanitize-html.js';

/**
 * Éditeur d'article.
 *
 * Deux onglets — « Visuel » (contenteditable) et « Code source » (textarea) —
 * présentent le même contenu.
 *
 * Le code source fait autorité : c'est lui qui part vers WordPress. L'onglet
 * visuel en affiche une version assainie (sans script ni gestionnaire
 * d'événement). Tant que l'utilisateur n'a rien modifié visuellement, le HTML
 * d'origine est renvoyé intact — assainir l'affichage ne doit pas amputer
 * silencieusement l'article.
 */
export function initEditor() {
    const root = document.getElementById('ag-editor');

    if (!root) {
        return;
    }

    const form = document.getElementById('ag-article-form');
    const surface = root.querySelector('[data-editor-surface]');
    const source = root.querySelector('[data-editor-source]');
    // Les onglets sont dans l'en-tête de la carte, hors de `root`.
    const tabs = document.querySelectorAll('[data-editor-tab]');
    const picker = createMediaPicker();

    const titleInput = document.getElementById('ag-title');
    const titleCounter = document.getElementById('ag-title-counter');
    const slugInput = document.getElementById('ag-slug');
    const featuredWrapper = document.getElementById('ag-featured');
    const imagesList = document.getElementById('ag-content-images');
    const auditPanel = document.getElementById('ag-audit-panel');

    let mode = 'visual';
    // Tant que ce drapeau est faux, le HTML d'origine est préservé tel quel.
    let visualDirty = false;

    /* --- Valeur courante --------------------------------------------------- */

    function currentHtml() {
        if (mode === 'source' || !visualDirty) {
            return source.value;
        }

        return surface.innerHTML;
    }

    function renderVisual() {
        surface.innerHTML = sanitizeHtml(source.value);
        visualDirty = false;
    }

    function applyHtml(html) {
        source.value = html;
        renderVisual();
        refreshImagesList();
    }

    /* --- Onglets ----------------------------------------------------------- */

    function setMode(next) {
        if (next === mode) return;

        if (mode === 'visual' && visualDirty) {
            source.value = surface.innerHTML;
        } else if (mode === 'source') {
            renderVisual();
        }

        mode = next;

        tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.editorTab === next);
            tab.setAttribute('aria-selected', String(tab.dataset.editorTab === next));
        });
        root.querySelector('.ag-editor__toolbar')?.toggleAttribute('hidden', next !== 'visual');
        surface.hidden = next !== 'visual';
        source.hidden = next !== 'source';
        (next === 'visual' ? surface : source).focus();
        refreshImagesList();
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => setMode(tab.dataset.editorTab));
    });

    /* --- Barre d'outils ---------------------------------------------------- */

    root.querySelectorAll('[data-command]').forEach((button) => {
        button.addEventListener('click', () => {
            surface.focus();

            const { command, value } = button.dataset;

            if (command === 'createLink') {
                const url = window.prompt('Adresse du lien');
                if (!url) return;
                document.execCommand(command, false, url);
            } else {
                document.execCommand(command, false, value ?? null);
            }

            visualDirty = true;
            source.value = surface.innerHTML;
        });
    });

    root.querySelector('[data-editor-insert-image]')?.addEventListener('click', async () => {
        const media = await picker.open();
        if (!media) return;

        surface.focus();
        document.execCommand(
            'insertHTML',
            false,
            `<figure class="wp-block-image"><img src="${escapeAttribute(media.url)}" alt="${escapeAttribute(
                media.alt,
            )}" /></figure>`,
        );

        visualDirty = true;
        source.value = surface.innerHTML;
        refreshImagesList();
    });

    /* --- Compteur de titre -------------------------------------------------- */

    function updateTitleCounter() {
        if (!titleCounter || !titleInput) return;

        // Même définition du mot que la règle d'audit : découpage sur les
        // espaces, un séparateur isolé (« : », « — ») ne compte pas.
        const words = titleInput.value
            .trim()
            .split(/\s+/)
            .filter((word) => /[\p{L}\p{N}]/u.test(word)).length;
        const max = Number(titleCounter.dataset.max ?? 20);

        titleCounter.textContent = `${words} / ${max} mots`;
        titleCounter.classList.toggle('text-danger', words > max);
        titleCounter.classList.toggle('ag-muted', words <= max);
    }

    titleInput?.addEventListener('input', updateTitleCounter);
    updateTitleCounter();

    /* --- Image mise en avant ------------------------------------------------ */

    featuredWrapper?.addEventListener('click', async (event) => {
        if (event.target.closest('[data-featured-replace]')) {
            const media = await picker.open();
            if (!media) return;
            setFeatured(media.id, media.url, media.alt);
        }

        if (event.target.closest('[data-featured-remove]')) {
            setFeatured(0, null, '');
        }
    });

    function setFeatured(id, url, alt) {
        featuredWrapper.querySelector('[name="featured_media_id"]').value = String(id);

        const preview = featuredWrapper.querySelector('[data-featured-preview]');
        const empty = featuredWrapper.querySelector('[data-featured-empty]');
        const removeButton = featuredWrapper.querySelector('[data-featured-remove]');

        if (url) {
            preview.src = url;
            preview.alt = alt ?? '';
            preview.hidden = false;
            empty.hidden = true;
            if (removeButton) removeButton.hidden = false;
        } else {
            preview.hidden = true;
            preview.removeAttribute('src');
            empty.hidden = false;
            if (removeButton) removeButton.hidden = true;
        }
    }

    /* --- Images du contenu -------------------------------------------------- */

    function refreshImagesList() {
        if (!imagesList) return;

        const doc = new DOMParser().parseFromString(currentHtml(), 'text/html');
        const images = Array.from(doc.querySelectorAll('img'));

        if (images.length === 0) {
            imagesList.innerHTML =
                '<p class="ag-hint mb-0">Aucune image dans le contenu de cet article.</p>';
            return;
        }

        imagesList.innerHTML = images
            .map((img, index) => {
                const src = img.getAttribute('src') ?? '';
                const alt = img.getAttribute('alt') ?? '';
                const link = imageLink(img);

                return `
                <div class="ag-image-row" data-image-index="${index}">
                    <div class="ag-image-row__head">
                        <img src="${escapeAttribute(src)}" alt="" loading="lazy">
                        <div class="ag-image-row__meta">
                            <span class="ag-image-row__index">Image ${index + 1}</span>
                            <a class="ag-image-row__name ag-mono" href="${escapeAttribute(src)}"
                               target="_blank" rel="noopener noreferrer" title="${escapeAttribute(src)}">${escapeHtml(
                                fileNameOf(src),
                            )}</a>
                        </div>
                    </div>
                    <textarea class="form-control form-control-sm" rows="2" placeholder="Texte alternatif"
                        data-image-alt aria-label="Texte alternatif de l'image ${index + 1}">${escapeHtml(alt)}</textarea>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text" title="Lien de l'image"><i class="bi bi-link-45deg" aria-hidden="true"></i></span>
                        <input type="url" class="form-control" value="${escapeAttribute(link)}"
                            placeholder="Lien (optionnel)" data-image-link
                            aria-label="Lien de l'image ${index + 1}">
                        <a class="btn btn-outline-secondary${link ? '' : ' disabled'}" href="${escapeAttribute(
                            link || '#',
                        )}" target="_blank" rel="noopener noreferrer" data-image-link-open
                            ${link ? '' : 'aria-disabled="true" tabindex="-1"'}
                            title="Ouvrir le lien" aria-label="Ouvrir le lien de l'image ${index + 1}">
                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <div class="ag-image-row__actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-image-replace>
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Remplacer
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-image-remove>
                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Retirer
                        </button>
                    </div>
                </div>`;
            })
            .join('');
    }

    imagesList?.addEventListener('click', async (event) => {
        const row = event.target.closest('[data-image-index]');
        if (!row) return;

        const index = Number(row.dataset.imageIndex);

        if (event.target.closest('[data-image-replace]')) {
            await replaceImageAt(index);
        }

        if (event.target.closest('[data-image-remove]')) {
            removeImageAt(index);
        }
    });

    imagesList?.addEventListener('change', (event) => {
        const row = event.target.closest('[data-image-index]');
        if (!row) return;

        const index = Number(row.dataset.imageIndex);

        if (event.target.closest('[data-image-alt]')) {
            applyHtml(mutateImage(currentHtml(), index, (img) => img.setAttribute('alt', event.target.value)));
        }

        if (event.target.closest('[data-image-link]')) {
            setLinkAt(index, event.target.value);
        }
    });

    /* --- Actions sur une image (liste et éditeur visuel) --------------------- */

    async function replaceImageAt(index) {
        const media = await picker.open();
        if (!media) return false;

        applyHtml(mutateImage(currentHtml(), index, (img) => replaceImage(img, media)));
        notify.info('Image remplacée. Cliquez sur « Mettre à jour » pour l’envoyer à WordPress.');

        return true;
    }

    function removeImageAt(index) {
        applyHtml(
            mutateImage(currentHtml(), index, (img) => {
                // Le lien qui n'entourait que l'image disparaît avec elle.
                const anchor = img.closest('a');
                const target = img.closest('figure') ?? (anchor && anchor.textContent.trim() === '' ? anchor : img);
                target.remove();
            }),
        );

        notify.info('Image retirée du contenu.');
    }

    function setLinkAt(index, value) {
        const url = safeUrl(value);

        if (url === null) {
            notify.error('Lien invalide : saisissez une adresse commençant par http:// ou https://.');
            refreshImagesList();
            return false;
        }

        applyHtml(mutateImage(currentHtml(), index, (img) => setImageLink(img, url)));
        notify.info(url ? 'Lien de l’image mis à jour.' : 'Lien de l’image retiré.');

        return true;
    }

    /* --- Sélection d'une image dans l'éditeur visuel ------------------------- */

    // Dans une zone éditable, le navigateur neutralise les liens : une image
    // entourée d'un lien client n'est ni cliquable ni modifiable. Un clic sur
    // l'image ouvre donc un panneau d'actions, lien compris.
    const popover = createImagePopover(root);
    let selectedIndex = null;

    surface.addEventListener('click', (event) => {
        const img = event.target.closest('img');

        if (img && surface.contains(img)) {
            event.preventDefault();
            openPopover(img);
            return;
        }

        // Ctrl/Cmd + clic : ouvre un lien du contenu dans un nouvel onglet.
        const anchor = event.target.closest('a[href]');
        if (anchor && (event.ctrlKey || event.metaKey) && safeUrl(anchor.getAttribute('href'))) {
            window.open(anchor.getAttribute('href'), '_blank', 'noopener');
            return;
        }

        closePopover();
    });

    function openPopover(img) {
        selectedIndex = sourceIndexOf(img);

        if (selectedIndex < 0) {
            closePopover();
            return;
        }

        const link = imageLink(img);
        popover.alt.value = img.getAttribute('alt') ?? '';
        popover.link.value = link;
        popover.setOpenLink(link);
        popover.title.textContent = `Image ${selectedIndex + 1}`;
        popover.show(img);
    }

    function closePopover() {
        selectedIndex = null;
        popover.hide();
    }

    popover.element.addEventListener('click', async (event) => {
        const action = event.target.closest('[data-pop]')?.dataset.pop;
        if (!action || selectedIndex === null) return;

        const index = selectedIndex;

        if (action === 'close') {
            closePopover();
        } else if (action === 'replace') {
            closePopover();
            await replaceImageAt(index);
        } else if (action === 'remove') {
            closePopover();
            removeImageAt(index);
        } else if (action === 'apply') {
            const url = safeUrl(popover.link.value);

            if (url === null) {
                notify.error('Lien invalide : saisissez une adresse commençant par http:// ou https://.');
                popover.link.focus();
                return;
            }

            applyHtml(
                mutateImage(currentHtml(), index, (img) => {
                    img.setAttribute('alt', popover.alt.value);
                    setImageLink(img, url);
                }),
            );
            closePopover();
            notify.info('Image modifiée. Cliquez sur « Mettre à jour » pour l’envoyer à WordPress.');
        }
    });

    popover.link.addEventListener('input', () => popover.setOpenLink(safeUrl(popover.link.value) || ''));

    popover.element.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closePopover();
            surface.focus();
        } else if (event.key === 'Enter' && event.target.matches('input')) {
            event.preventDefault();
            popover.element.querySelector('[data-pop="apply"]').click();
        }
    });

    surface.addEventListener('scroll', closePopover);
    window.addEventListener('resize', closePopover);
    document.addEventListener('mousedown', (event) => {
        if (!popover.element.hidden && !popover.element.contains(event.target) && !surface.contains(event.target)) {
            closePopover();
        }
    });

    /**
     * Position de l'image cliquée dans le HTML de référence. La surface est
     * une copie assainie : on rapproche les deux par l'adresse de l'image et
     * son rang parmi les images de même adresse.
     */
    function sourceIndexOf(img) {
        const src = img.getAttribute('src') ?? '';
        const occurrence = Array.from(surface.querySelectorAll('img'))
            .filter((candidate) => (candidate.getAttribute('src') ?? '') === src)
            .indexOf(img);

        const doc = new DOMParser().parseFromString(`<div id="ag-wrap">${currentHtml()}</div>`, 'text/html');
        let seen = -1;

        return Array.from(doc.querySelectorAll('#ag-wrap img')).findIndex(
            (candidate) => (candidate.getAttribute('src') ?? '') === src && ++seen === occurrence,
        );
    }

    /* --- Colonne d'audit : chaque remarque mène à la zone concernée ---------- */

    auditPanel?.addEventListener('click', (event) => {
        const item = event.target.closest('[data-audit-target]');
        if (!item || item.disabled) return;

        const target = item.dataset.auditTarget;

        if (target === 'title') {
            titleInput?.focus();
            titleInput?.select();
        } else if (target === 'featured_image') {
            scrollAndFlash(featuredWrapper);
        } else if (target === 'images') {
            scrollAndFlash(imagesList);
        } else {
            setMode('visual');
            surface.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Met en évidence les H1 en trop le temps que l'utilisateur les repère.
            if (item.dataset.auditType === 'multiple_h1') {
                surface.classList.add('is-highlighting');
                setTimeout(() => surface.classList.remove('is-highlighting'), 4000);
            }
        }
    });

    /* --- Enregistrement ------------------------------------------------------ */

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const button = form.querySelector('[data-save]');
        const done = busy(button, 'Envoi à WordPress…');

        // Un site lent peut mettre plus d'une minute à enregistrer : on le dit,
        // pour que l'attente ne passe pas pour un blocage.
        const slowNotice = setTimeout(() => {
            const label = button?.lastChild;
            if (label?.nodeType === Node.TEXT_NODE) {
                label.textContent = 'WordPress répond lentement, patientez…';
            }
        }, 10000);

        const payload = {
            title: titleInput.value,
            content: currentHtml(),
            slug: slugInput?.value ?? null,
            status: form.querySelector('[name="status"]')?.value ?? null,
            featured_media_id: Number(
                featuredWrapper?.querySelector('[name="featured_media_id"]')?.value ?? 0,
            ),
            categories: Array.from(form.querySelectorAll('[name="categories[]"]:checked')).map((input) =>
                Number(input.value),
            ),
        };

        try {
            const data = await http.put(form.dataset.url, payload);

            notify.success(data.message);

            // Le contenu renvoyé par WordPress devient la nouvelle référence.
            source.value = payload.content;
            visualDirty = false;

            updateAuditPanel(data.audit);
        } catch (error) {
            notify.error(error.message);
        } finally {
            clearTimeout(slowNotice);
            done();
        }
    });

    /* --- Audit manuel -------------------------------------------------------- */

    document.getElementById('ag-run-audit')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const done = busy(button, 'Audit…');

        try {
            const data = await http.post(button.dataset.url, {});
            notify.success(data.message);
            updateAuditPanel(data.audit);
        } catch (error) {
            notify.error(error.message);
        } finally {
            done();
        }
    });

    function updateAuditPanel(audit) {
        if (!audit?.panel || !auditPanel) return;

        auditPanel.innerHTML = audit.panel;
        auditPanel.classList.add('ag-fade-in');
        setTimeout(() => auditPanel.classList.remove('ag-fade-in'), 250);
    }

    /* --- Synchronisation des deux onglets ------------------------------------ */

    surface.addEventListener('input', () => {
        visualDirty = true;
        source.value = surface.innerHTML;
    });

    source.addEventListener('input', () => {
        renderVisual();
    });

    /* --- Initialisation ------------------------------------------------------ */

    renderVisual();
    refreshImagesList();
}

function scrollAndFlash(element) {
    if (!element) return;

    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    element.classList.add('ag-row-flash');
    setTimeout(() => element.classList.remove('ag-row-flash'), 1500);
}

/**
 * Applique une transformation à la n-ième image du HTML, puis renvoie le HTML
 * résultant. Passer par DOMParser évite les réécritures hasardeuses par regex.
 */
function mutateImage(html, index, mutate) {
    const doc = new DOMParser().parseFromString(`<div id="ag-wrap">${html}</div>`, 'text/html');
    const images = doc.querySelectorAll('#ag-wrap img');

    if (images[index]) {
        mutate(images[index]);
    }

    return doc.getElementById('ag-wrap').innerHTML;
}

/**
 * Remplace une image par un média WordPress, sans laisser de trace de
 * l'ancienne : `srcset`/`sizes` pointent vers les déclinaisons de l'ancienne
 * image — le navigateur les préfère à `src`, et l'ancienne image resterait
 * affichée en ligne. Les dimensions d'origine déformeraient la nouvelle.
 */
function replaceImage(img, media) {
    img.setAttribute('src', media.url);
    if (media.alt) img.setAttribute('alt', media.alt);

    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    img.removeAttribute('data-src');
    img.removeAttribute('data-srcset');

    if (media.width && media.height) {
        img.setAttribute('width', String(media.width));
        img.setAttribute('height', String(media.height));
    } else {
        img.removeAttribute('width');
        img.removeAttribute('height');
    }

    // <picture> : les <source> proposent encore l'ancienne image.
    img.closest('picture')?.querySelectorAll('source').forEach((source) => source.remove());

    if (media.id) {
        const classes = (img.getAttribute('class') ?? '').replace(/\bwp-image-\d+\b/, '').trim();
        img.setAttribute('class', `${classes} wp-image-${media.id}`.trim());
        updateBlockId(img, media.id);
    }
}

/**
 * Le commentaire de bloc Gutenberg (`<!-- wp:image {"id":12} -->`) référence
 * aussi le média : sans mise à jour, l'éditeur WordPress signalerait un bloc
 * invalide à la prochaine ouverture.
 */
function updateBlockId(img, id) {
    let node = img.closest('figure') ?? img;

    while (node && node.parentNode && !node.previousSibling) {
        node = node.parentNode;
    }

    let previous = node?.previousSibling;
    while (previous && previous.nodeType === Node.TEXT_NODE && previous.textContent.trim() === '') {
        previous = previous.previousSibling;
    }

    if (previous?.nodeType === Node.COMMENT_NODE && /^\s*wp:image\b/.test(previous.data)) {
        previous.data = previous.data.replace(/"id":\d+/, `"id":${id}`);
    }
}

function imageLink(img) {
    return img.closest('a[href]')?.getAttribute('href') ?? '';
}

/**
 * Pose, modifie ou retire le lien d'une image. Un lien retiré laisse son
 * contenu en place.
 */
function setImageLink(img, url) {
    const anchor = img.closest('a');

    if (!url) {
        anchor?.replaceWith(...anchor.childNodes);
        return;
    }

    if (anchor) {
        anchor.setAttribute('href', url);
        return;
    }

    const link = img.ownerDocument.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('target', '_blank');
    link.setAttribute('rel', 'noopener');
    img.replaceWith(link);
    link.appendChild(img);
}

/**
 * Adresse http(s) valide, chaîne vide si rien n'est saisi, `null` sinon.
 */
function safeUrl(value) {
    const trimmed = String(value ?? '').trim();
    if (!trimmed) return '';

    try {
        const url = new URL(trimmed);
        return ['http:', 'https:'].includes(url.protocol) ? trimmed : null;
    } catch {
        return null;
    }
}

/**
 * Panneau d'actions affiché sur l'image sélectionnée dans l'éditeur visuel.
 */
function createImagePopover(root) {
    const element = document.createElement('div');
    element.className = 'ag-img-popover';
    element.hidden = true;
    element.setAttribute('role', 'dialog');
    element.setAttribute('aria-label', 'Image sélectionnée');
    element.innerHTML = `
        <div class="ag-img-popover__head">
            <strong data-pop-title>Image</strong>
            <button type="button" class="btn-close btn-sm ms-auto" data-pop="close" aria-label="Fermer"></button>
        </div>
        <label class="form-label small mb-1" for="ag-pop-alt">Texte alternatif</label>
        <input type="text" id="ag-pop-alt" class="form-control form-control-sm mb-2" data-pop-alt>
        <label class="form-label small mb-1" for="ag-pop-link">Lien</label>
        <div class="input-group input-group-sm mb-2">
            <input type="url" id="ag-pop-link" class="form-control" placeholder="https://…" data-pop-link>
            <a class="btn btn-outline-secondary" target="_blank" rel="noopener noreferrer" data-pop-open
               title="Ouvrir le lien" aria-label="Ouvrir le lien dans un nouvel onglet">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
            </a>
        </div>
        <div class="ag-img-popover__actions">
            <button type="button" class="btn btn-sm btn-primary" data-pop="apply">Appliquer</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-pop="replace">
                <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Remplacer
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-pop="remove"
                    title="Retirer l'image" aria-label="Retirer l'image">
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
        </div>`;

    // Cadre de sélection dessiné par-dessus l'image : une classe posée sur
    // l'image elle-même finirait dans le HTML envoyé à WordPress.
    const frame = document.createElement('div');
    frame.className = 'ag-img-frame';
    frame.hidden = true;

    root.append(frame, element);

    const openLink = element.querySelector('[data-pop-open]');

    return {
        element,
        alt: element.querySelector('[data-pop-alt]'),
        link: element.querySelector('[data-pop-link]'),
        title: element.querySelector('[data-pop-title]'),
        setOpenLink(url) {
            if (url) {
                openLink.href = url;
                openLink.classList.remove('disabled');
                openLink.removeAttribute('aria-disabled');
                openLink.removeAttribute('tabindex');
            } else {
                openLink.removeAttribute('href');
                openLink.classList.add('disabled');
                openLink.setAttribute('aria-disabled', 'true');
                openLink.setAttribute('tabindex', '-1');
            }
        },
        show(img) {
            const box = root.getBoundingClientRect();
            const rect = img.getBoundingClientRect();

            Object.assign(frame.style, {
                top: `${rect.top - box.top}px`,
                left: `${rect.left - box.left}px`,
                width: `${rect.width}px`,
                height: `${rect.height}px`,
            });
            frame.hidden = false;
            element.hidden = false;

            // Sous l'image si la place le permet, sinon au-dessus ; toujours
            // dans les limites de l'éditeur.
            const width = element.offsetWidth;
            const height = element.offsetHeight;
            let top = rect.bottom - box.top + 8;
            if (top + height > box.height) top = Math.max(8, rect.top - box.top - height - 8);
            const left = Math.min(Math.max(8, rect.left - box.left), Math.max(8, box.width - width - 8));

            element.style.top = `${top}px`;
            element.style.left = `${left}px`;
            element.querySelector('[data-pop-alt]').focus();
        },
        hide() {
            element.hidden = true;
            frame.hidden = true;
        },
    };
}

function fileNameOf(src) {
    try {
        return decodeURIComponent(new URL(src, window.location.origin).pathname.split('/').pop() ?? src);
    } catch {
        return src;
    }
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}

function escapeAttribute(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}
