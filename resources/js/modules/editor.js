import { http } from './http.js';
import { notify } from './toast.js';
import { busy } from './busy.js';
import { createMediaPicker } from './media-picker.js';
import { createImageDetails } from './image-details.js';
import { sanitizeHtml } from './sanitize-html.js';
import { fileNameOf, safeUrl, sameUrl } from './url.js';
import { blockLabel, createBlockIndicator, createLinkPopover, createOutline, currentBlock } from './editor-structure.js';

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
    const imageDetails = createImageDetails({ mediaUrl: picker.indexUrl });

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
        // Les repères pointaient vers des éléments qui viennent d'être remplacés.
        linkPopover.hide();
        blockIndicator.hide();
    }

    function applyHtml(html) {
        source.value = html;
        renderVisual();
        refreshImagesList();
        scheduleStructure();
    }

    /** Une modification faite dans l'onglet visuel devient la référence. */
    function syncFromSurface() {
        visualDirty = true;
        source.value = surface.innerHTML;
        scheduleStructure();
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
        closePopover();
        linkPopover.hide();
        blockIndicator.hide();

        tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.editorTab === next);
            tab.setAttribute('aria-selected', String(tab.dataset.editorTab === next));
        });
        root.querySelector('.ag-editor__toolbar')?.toggleAttribute('hidden', next !== 'visual');
        surface.hidden = next !== 'visual';
        source.hidden = next !== 'source';
        (next === 'visual' ? surface : source).focus();
        refreshImagesList();
        scheduleStructure();
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => setMode(tab.dataset.editorTab));
    });

    /* --- Barre d'outils ---------------------------------------------------- */

    const commandButtons = root.querySelectorAll('[data-command]');
    const blockSelect = root.querySelector('[data-editor-block]');
    const blockOther = blockSelect?.querySelector('[data-editor-block-other]');

    // Dernière sélection dans la zone éditable : un clic dans la barre
    // d'outils (ou sur le sélecteur de bloc) peut la faire perdre.
    let savedRange = null;

    function restoreSelection() {
        surface.focus();

        if (savedRange && surface.contains(savedRange.startContainer)) {
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(savedRange);
        }
    }

    /** Boutons actifs et type de bloc, d'après la position du curseur. */
    function refreshToolbarState() {
        if (mode !== 'visual') return;

        const selection = window.getSelection();
        const inSurface = Boolean(selection?.rangeCount) && surface.contains(selection.anchorNode);
        const tag = inSurface ? (currentBlock(surface)?.tagName.toLowerCase() ?? null) : null;

        commandButtons.forEach((button) => {
            const { command, value } = button.dataset;
            let active = null;

            if (command === 'formatBlock') {
                active = tag === value;
            } else if (['bold', 'italic', 'insertUnorderedList', 'insertOrderedList'].includes(command)) {
                active = inSurface && document.queryCommandState(command);
            }

            if (active !== null) {
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            }
        });

        if (!blockSelect || !inSurface) return;

        if (tag && blockSelect.querySelector(`option[value="${tag}"]`)) {
            blockSelect.value = tag;
        } else if (tag && blockOther) {
            blockOther.textContent = blockLabel(tag);
            blockSelect.value = '';
        } else {
            blockSelect.value = 'p';
        }
    }

    blockSelect?.addEventListener('change', () => {
        if (!blockSelect.value) return;

        restoreSelection();
        document.execCommand('formatBlock', false, blockSelect.value);
        syncFromSurface();
        refreshToolbarState();
        blockIndicator.update();
    });

    commandButtons.forEach((button) => {
        button.addEventListener('click', () => {
            restoreSelection();

            const { command, value } = button.dataset;

            if (command === 'createLink') {
                const url = window.prompt('Adresse du lien');
                if (!url) return;
                document.execCommand(command, false, url);
            } else {
                document.execCommand(command, false, value ?? null);
            }

            syncFromSurface();
            refreshToolbarState();
            blockIndicator.update();
        });
    });

    root.querySelector('[data-editor-insert-image]')?.addEventListener('click', async () => {
        const media = await picker.open();
        if (!media) return;

        restoreSelection();
        document.execCommand(
            'insertHTML',
            false,
            `<figure class="wp-block-image"><img src="${escapeAttribute(media.url)}" alt="${escapeAttribute(
                media.alt,
            )}" /></figure>`,
        );

        syncFromSurface();
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

        // Rouvre la médiathèque sur l'image déjà choisie : le panneau
        // « Détails du fichier joint » s'affiche directement sur elle.
        if (event.target.closest('[data-featured-details]')) {
            const current = Number(
                featuredWrapper.querySelector('[name="featured_media_id"]')?.value ?? 0,
            );
            const media = await picker.open({ mediaId: current || null });
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
        const detailsButton = featuredWrapper.querySelector('[data-featured-details]');
        const summary = featuredWrapper.querySelector('[data-featured-summary]');
        const altCell = featuredWrapper.querySelector('[data-featured-alt]');
        const replaceButton = featuredWrapper.querySelector('[data-featured-replace]');

        if (url) {
            preview.src = url;
            preview.alt = alt ?? '';
            preview.hidden = false;
            empty.hidden = true;
            if (removeButton) removeButton.hidden = false;
            if (replaceButton) replaceButton.textContent = 'Remplacer';
        } else {
            preview.hidden = true;
            preview.removeAttribute('src');
            empty.hidden = false;
            if (removeButton) removeButton.hidden = true;
            if (replaceButton) replaceButton.textContent = 'Choisir une image';
        }

        if (detailsButton) detailsButton.hidden = !id;
        if (summary) summary.hidden = !url;

        if (altCell) {
            altCell.textContent = alt || 'Non renseigné';
            altCell.classList.toggle('is-missing', !alt);
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
                const settings = readImageSettings(img);

                return `
                <div class="ag-image-row" data-image-index="${index}" data-image-src="${escapeAttribute(settings.src)}">
                    <div class="ag-image-row__head">
                        <img src="${escapeAttribute(settings.src)}" alt="" loading="lazy">
                        <div class="ag-image-row__meta">
                            <span class="ag-image-row__index">Image ${index + 1}</span>
                            <a class="ag-image-row__name ag-mono" href="${escapeAttribute(settings.src)}"
                               target="_blank" rel="noopener noreferrer" title="${escapeAttribute(
                                   settings.src,
                               )}">${escapeHtml(settings.filename)}</a>
                        </div>
                    </div>
                    <dl class="ag-image-row__summary">
                        <dt>Texte alternatif</dt>
                        <dd class="${settings.alt ? '' : 'is-missing'}">${escapeHtml(
                            settings.alt || 'Non renseigné',
                        )}</dd>
                        ${
                            settings.caption
                                ? `<dt>Légende</dt><dd>${escapeHtml(settings.caption)}</dd>`
                                : ''
                        }
                        ${
                            settings.link
                                ? `<dt>Lien</dt><dd class="text-truncate">${escapeHtml(settings.link)}</dd>`
                                : ''
                        }
                    </dl>
                    <div class="ag-image-row__tags">
                        <span class="ag-chip">${escapeHtml(ALIGN_LABELS[settings.align])}</span>
                        <span class="ag-chip">${escapeHtml(sizeLabel(settings))}</span>
                    </div>
                    <div class="ag-image-row__actions">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-image-details>
                            <i class="bi bi-sliders me-1" aria-hidden="true"></i>Détails
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-image-replace>
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Remplacer
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-image-remove
                                title="Retirer l’image" aria-label="Retirer l’image ${index + 1}">
                            <i class="bi bi-trash" aria-hidden="true"></i>
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

        if (event.target.closest('[data-image-details]')) {
            await openImageDetails(index);
        }

        if (event.target.closest('[data-image-replace]')) {
            await replaceImageAt(index);
        }

        if (event.target.closest('[data-image-remove]')) {
            removeImageAt(index);
        }
    });

    /* --- Fenêtre « Détails de l'image » ------------------------------------- */

    /**
     * Ouvre les détails de la n-ième image du contenu, puis applique l'action
     * choisie. Un remplacement rouvre la fenêtre sur la nouvelle image : c'est
     * le moment où l'on renseigne son texte alternatif et sa taille.
     */
    async function openImageDetails(index) {
        const settings = imageSettingsAt(index);
        if (!settings) return;

        const result = await imageDetails.open(settings);

        if (!result) return;

        if (result.action === 'remove') {
            removeImageAt(index);
            return;
        }

        if (result.action === 'replace') {
            if (await replaceImageAt(index)) {
                await openImageDetails(index);
            }
            return;
        }

        applyHtml(mutateImage(currentHtml(), index, (img) => applyImageSettings(img, result)));
        notify.info('Image modifiée. Cliquez sur « Mettre à jour » pour l’envoyer à WordPress.');
    }

    /** Réglages de la n-ième image, lus dans le HTML de référence. */
    function imageSettingsAt(index) {
        const doc = new DOMParser().parseFromString(`<div id="ag-wrap">${currentHtml()}</div>`, 'text/html');
        const img = doc.querySelectorAll('#ag-wrap img')[index];

        return img ? readImageSettings(img) : null;
    }

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

    /* --- Sélection d'une image dans l'éditeur visuel ------------------------- */

    // Dans une zone éditable, le navigateur neutralise les liens : une image
    // entourée d'un lien client n'est ni cliquable ni modifiable. Un clic sur
    // l'image ouvre donc un panneau d'actions, lien compris.
    const popover = createImagePopover(root);
    let selectedIndex = null;

    // Même principe pour les liens : un clic sur un lien affiche son adresse,
    // cliquable, avec de quoi le modifier ou le retirer.
    const linkPopover = createLinkPopover(root, {
        onChange: () => {
            syncFromSurface();
            notify.info('Lien modifié. Cliquez sur « Mettre à jour » pour l’envoyer à WordPress.');
        },
        onError: (message) => notify.error(message),
    });

    // Repère « H2 · Titre », « Paragraphe »… au-dessus du bloc courant.
    const blockIndicator = createBlockIndicator(root, surface);

    surface.addEventListener('click', (event) => {
        const img = event.target.closest('img');

        if (img && surface.contains(img)) {
            event.preventDefault();
            linkPopover.hide();
            openPopover(img);
            return;
        }

        const anchor = event.target.closest('a[href]');

        if (anchor && surface.contains(anchor)) {
            // Ctrl/Cmd + clic : ouvre directement le lien dans un nouvel onglet.
            if ((event.ctrlKey || event.metaKey) && safeUrl(anchor.getAttribute('href'))) {
                window.open(anchor.getAttribute('href'), '_blank', 'noopener');
                return;
            }

            closePopover();
            linkPopover.show(anchor);
            return;
        }

        closePopover();
        linkPopover.hide();
    });

    // Saisir du texte referme le panneau du lien, comme dans WordPress.
    surface.addEventListener('keydown', (event) => {
        if (!['Shift', 'Control', 'Meta', 'Alt'].includes(event.key)) {
            linkPopover.hide();
        }
    });

    document.addEventListener('selectionchange', () => {
        const selection = window.getSelection();

        if (selection?.rangeCount && surface.contains(selection.anchorNode)) {
            savedRange = selection.getRangeAt(0).cloneRange();
        }

        refreshToolbarState();
        blockIndicator.update();
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
        } else if (action === 'details') {
            closePopover();
            await openImageDetails(index);
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

    surface.addEventListener('scroll', () => {
        closePopover();
        linkPopover.hide();
        blockIndicator.update();
    });
    window.addEventListener('resize', () => {
        closePopover();
        linkPopover.hide();
        blockIndicator.update();
    });
    document.addEventListener('mousedown', (event) => {
        if (surface.contains(event.target)) return;

        if (!popover.element.hidden && !popover.element.contains(event.target)) {
            closePopover();
        }

        if (!linkPopover.element.hidden && !linkPopover.element.contains(event.target)) {
            linkPopover.hide();
        }
    });

    /* --- Plan des titres (H1 : 1, H2 : 4…) ------------------------------------ */

    const outlineContainer = root.querySelector('[data-editor-outline]');
    const outline = outlineContainer ? createOutline(outlineContainer, { onSelect: goToHeading }) : null;
    let structureTimer = null;

    function scheduleStructure() {
        clearTimeout(structureTimer);
        structureTimer = setTimeout(() => outline?.update(currentHtml()), 150);
    }

    /** Amène le curseur au début du n-ième titre, dans l'onglet visuel. */
    function goToHeading(index) {
        setMode('visual');

        const heading = surface.querySelectorAll('h1,h2,h3,h4,h5,h6')[index];
        if (!heading) return;

        heading.scrollIntoView({ behavior: 'smooth', block: 'center' });

        const range = document.createRange();
        range.selectNodeContents(heading);
        range.collapse(true);
        surface.focus({ preventScroll: true });

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

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
            // Une remarque sur une image précise mène à cette image dans la
            // liste « Images du contenu », à défaut à la liste entière.
            const src = item.dataset.auditSrc;
            const row = src
                ? Array.from(imagesList?.querySelectorAll('[data-image-src]') ?? []).find((candidate) =>
                      sameUrl(candidate.dataset.imageSrc, src),
                  )
                : null;
            scrollAndFlash(row ?? imagesList);
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
        syncFromSurface();
        blockIndicator.update();
    });

    source.addEventListener('input', () => {
        renderVisual();
        scheduleStructure();
    });

    /* --- Initialisation ------------------------------------------------------ */

    renderVisual();
    refreshImagesList();
    outline?.update(currentHtml());
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

const ALIGN_LABELS = {
    none: 'Aucun alignement',
    left: 'Aligné à gauche',
    center: 'Centré',
    right: 'Aligné à droite',
};

/**
 * Lit les réglages d'affichage d'une image tels que WordPress les écrit :
 * classes `align*` et `size-*`, légende dans un `<figcaption>`, lien porté par
 * un `<a>` englobant, identifiant du média dans `wp-image-{id}`.
 */
function readImageSettings(img) {
    const figure = img.closest('figure');
    const classes = `${figure?.getAttribute('class') ?? ''} ${img.getAttribute('class') ?? ''}`;
    const src = img.getAttribute('src') ?? '';

    return {
        src,
        filename: fileNameOf(src),
        alt: img.getAttribute('alt') ?? '',
        link: imageLink(img),
        caption: figure?.querySelector('figcaption')?.textContent.trim() ?? '',
        align: classes.match(/\balign(left|center|right|none)\b/)?.[1] ?? 'none',
        sizeName: classes.match(/\bsize-([\w-]+)\b/)?.[1] ?? null,
        width: positiveInt(img.getAttribute('width')),
        height: positiveInt(img.getAttribute('height')),
        naturalWidth: positiveInt(img.getAttribute('width')),
        naturalHeight: positiveInt(img.getAttribute('height')),
        mediaId: positiveInt(classes.match(/\bwp-image-(\d+)\b/)?.[1]),
    };
}

/**
 * Écrit les réglages choisis dans le HTML de l'article. L'ordre compte : le
 * lien est posé avant la légende, pour que le `<figure>` englobe le `<a>` et
 * non l'inverse — c'est la structure attendue par WordPress.
 */
function applyImageSettings(img, values) {
    img.setAttribute('alt', values.alt ?? '');

    if (values.size?.url && values.size.url !== img.getAttribute('src')) {
        img.setAttribute('src', values.size.url);
        // Les déclinaisons de l'ancienne taille seraient préférées à `src`.
        img.removeAttribute('srcset');
        img.removeAttribute('sizes');
        img.closest('picture')
            ?.querySelectorAll('source')
            .forEach((source) => source.remove());
    }

    setAttribute(img, 'width', values.width);
    setAttribute(img, 'height', values.height);

    setImageLink(img, values.link);

    const figure = ensureCaption(img, values.caption);
    const holder = figure ?? img;

    setClassGroup(holder, /\balign(?:left|center|right|none)\b/g, values.align === 'none' ? null : `align${values.align}`);
    setClassGroup(holder, /\bsize-[\w-]+\b/g, values.size?.name && values.size.name !== 'custom' ? `size-${values.size.name}` : null);

    // Un réglage posé sur le `<figure>` ne doit pas rester en double sur
    // l'image : WordPress n'en applique qu'un seul.
    if (figure) {
        setClassGroup(img, /\balign(?:left|center|right|none)\b/g, null);
        setClassGroup(img, /\bsize-[\w-]+\b/g, null);
    }
}

/**
 * Ajoute, met à jour ou retire la légende. Une légende sur une image sans
 * `<figure>` en crée un ; une légende vidée retire le `<figcaption>` mais
 * laisse la structure en place, qui peut porter d'autres réglages.
 */
function ensureCaption(img, caption) {
    let figure = img.closest('figure');

    if (!caption) {
        figure?.querySelector('figcaption')?.remove();

        return figure;
    }

    if (!figure) {
        const target = img.closest('a') ?? img;
        figure = img.ownerDocument.createElement('figure');
        figure.setAttribute('class', 'wp-block-image');
        target.replaceWith(figure);
        figure.appendChild(target);
    }

    let figcaption = figure.querySelector('figcaption');

    if (!figcaption) {
        figcaption = img.ownerDocument.createElement('figcaption');
        figure.appendChild(figcaption);
    }

    figcaption.textContent = caption;

    return figure;
}

/** Remplace la classe d'un groupe (alignement, taille) par une autre. */
function setClassGroup(element, pattern, replacement) {
    const classes = (element.getAttribute('class') ?? '').replace(pattern, '').trim().replace(/\s+/g, ' ');
    const next = replacement ? `${classes} ${replacement}`.trim() : classes;

    if (next) {
        element.setAttribute('class', next);
    } else {
        element.removeAttribute('class');
    }
}

function setAttribute(element, name, value) {
    if (value) {
        element.setAttribute(name, String(value));
    } else {
        element.removeAttribute(name);
    }
}

function positiveInt(value) {
    const number = Number.parseInt(value ?? '', 10);

    return Number.isFinite(number) && number > 0 ? number : null;
}

/** Libellé de la taille affiché dans la liste des images. */
function sizeLabel(settings) {
    if (settings.width && settings.height) {
        return `${settings.width} × ${settings.height} px`;
    }

    if (settings.sizeName) {
        return `Taille « ${settings.sizeName} »`;
    }

    return 'Taille d’origine';
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
        </div>
        <button type="button" class="btn btn-sm btn-link w-100 mt-1 p-0 text-decoration-none"
                data-pop="details">
            <i class="bi bi-sliders me-1" aria-hidden="true"></i>Détails de l’image (légende, alignement, taille)
        </button>`;

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
