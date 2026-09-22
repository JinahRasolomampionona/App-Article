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
            const media = await picker.open();
            if (!media) return;

            applyHtml(
                mutateImage(currentHtml(), index, (img) => {
                    img.setAttribute('src', media.url);
                    if (media.alt) img.setAttribute('alt', media.alt);
                }),
            );

            notify.info('Image remplacée. Cliquez sur « Mettre à jour » pour l’envoyer à WordPress.');
        }

        if (event.target.closest('[data-image-remove]')) {
            applyHtml(
                mutateImage(currentHtml(), index, (img) => {
                    const figure = img.closest('figure');
                    (figure ?? img).remove();
                }),
            );

            notify.info('Image retirée du contenu.');
        }
    });

    imagesList?.addEventListener('change', (event) => {
        const input = event.target.closest('[data-image-alt]');
        if (!input) return;

        const index = Number(input.closest('[data-image-index]').dataset.imageIndex);

        applyHtml(mutateImage(currentHtml(), index, (img) => img.setAttribute('alt', input.value)));
    });

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
