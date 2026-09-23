import { Modal } from 'bootstrap';
import { http } from './http.js';
import { notify } from './toast.js';
import { safeUrl, sameUrl } from './url.js';

/**
 * Fenêtre « Détails de l'image » d'une image du contenu.
 *
 * Elle reprend les réglages de l'éditeur WordPress : texte alternatif, lien,
 * légende, puis les réglages d'affichage (alignement et taille). Contrairement
 * aux détails du fichier joint, tout ce qui est réglé ici n'existe que dans le
 * HTML de l'article.
 *
 * `open(settings)` renvoie une promesse résolue avec l'action demandée
 * (`apply`, `replace`, `remove`) ou `null` si la fenêtre est fermée.
 */
export function createImageDetails({ mediaUrl } = {}) {
    const element = document.getElementById('ag-image-details-modal');

    if (!element) {
        return { open: async () => null };
    }

    const modal = new Modal(element);
    const field = (name) => element.querySelector(`[data-details-${name}]`);

    const preview = field('preview');
    const filename = field('filename');
    const alt = field('alt');
    const link = field('link');
    const linkOpen = field('link-open');
    const caption = field('caption');
    const sizeSelect = field('size');
    const width = field('width');
    const height = field('height');
    const ratio = field('ratio');
    const sizesNote = field('sizes-note');

    let resolver = null;
    let settled = false;
    // Proportions de référence, pour le calcul automatique de la hauteur.
    let aspect = null;
    // Médias déjà interrogés : rouvrir la fenêtre ne relance pas la requête.
    const sizesCache = new Map();

    function resolve(value) {
        settled = true;
        resolver?.(value);
        resolver = null;
    }

    function setOpenLink(url) {
        if (url) {
            linkOpen.href = url;
            linkOpen.classList.remove('disabled');
            linkOpen.removeAttribute('aria-disabled');
            linkOpen.removeAttribute('tabindex');
        } else {
            linkOpen.removeAttribute('href');
            linkOpen.classList.add('disabled');
            linkOpen.setAttribute('aria-disabled', 'true');
            linkOpen.setAttribute('tabindex', '-1');
        }
    }

    function setAlign(value) {
        const input =
            element.querySelector(`[data-details-align][value="${value}"]`) ??
            element.querySelector('[data-details-align][value="none"]');

        if (input) input.checked = true;
    }

    function currentAlign() {
        return element.querySelector('[data-details-align]:checked')?.value ?? 'none';
    }

    /* --- Tailles disponibles ------------------------------------------------ */

    function resetSizes() {
        sizeSelect.innerHTML = '<option value="custom">Taille personnalisée</option>';
        sizesNote.hidden = true;
    }

    function fillSizes(sizes, currentSrc) {
        resetSizes();

        sizes.forEach((size) => {
            const option = document.createElement('option');
            option.value = size.name;
            option.textContent =
                size.width && size.height
                    ? `${size.label} – ${size.width} × ${size.height}`
                    : size.label;
            option.dataset.url = size.url;
            option.dataset.width = size.width ?? '';
            option.dataset.height = size.height ?? '';
            sizeSelect.insertBefore(option, sizeSelect.lastElementChild);
        });

        sizesNote.hidden = sizes.length === 0;

        const match = sizes.find((size) => sameUrl(size.url, currentSrc));

        if (match) {
            sizeSelect.value = match.name;
            if (match.width) width.value = String(match.width);
            if (match.height) height.value = String(match.height);
        }
    }

    async function loadSizes(mediaId, currentSrc) {
        if (!mediaId || !mediaUrl) return;

        try {
            if (!sizesCache.has(mediaId)) {
                const data = await http.get(`${mediaUrl}/${mediaId}`);
                sizesCache.set(mediaId, data.media?.sizes ?? []);
            }

            // La fenêtre a pu être fermée ou rouverte sur une autre image
            // pendant la requête : on ne réécrit alors plus rien.
            if (!resolver || element.dataset.mediaId !== String(mediaId)) return;

            fillSizes(sizesCache.get(mediaId), currentSrc);
        } catch {
            // Sans les tailles WordPress, seule la taille personnalisée est
            // proposée : inutile d'alerter l'utilisateur.
        }
    }

    /* --- Dimensions --------------------------------------------------------- */

    sizeSelect.addEventListener('change', () => {
        const option = sizeSelect.selectedOptions[0];

        if (!option?.dataset.url) return;

        width.value = option.dataset.width || '';
        height.value = option.dataset.height || '';

        if (option.dataset.width && option.dataset.height) {
            aspect = Number(option.dataset.width) / Number(option.dataset.height);
        }
    });

    width.addEventListener('input', () => {
        sizeSelect.value = 'custom';

        if (ratio.checked && aspect && width.value) {
            height.value = String(Math.round(Number(width.value) / aspect));
        }
    });

    height.addEventListener('input', () => {
        sizeSelect.value = 'custom';

        if (ratio.checked && aspect && height.value) {
            width.value = String(Math.round(Number(height.value) * aspect));
        }
    });

    link.addEventListener('input', () => setOpenLink(safeUrl(link.value) || ''));

    // Une image insérée sans attribut de dimension n'a pas de proportions
    // connues tant qu'elle n'est pas chargée : on les reprend de l'aperçu.
    preview.addEventListener('load', () => {
        if (!aspect && preview.naturalWidth && preview.naturalHeight) {
            aspect = preview.naturalWidth / preview.naturalHeight;
        }
    });

    /* --- Actions ------------------------------------------------------------ */

    element.querySelector('[data-details-remove]').addEventListener('click', () => {
        resolve({ action: 'remove' });
        modal.hide();
    });

    element.querySelector('[data-details-replace]').addEventListener('click', () => {
        resolve({ action: 'replace' });
        modal.hide();
    });

    element.querySelector('[data-details-apply]').addEventListener('click', () => {
        const url = safeUrl(link.value);

        if (url === null) {
            notify.error('Lien invalide : saisissez une adresse commençant par http:// ou https://.');
            link.focus();
            return;
        }

        const option = sizeSelect.selectedOptions[0];

        resolve({
            action: 'apply',
            alt: alt.value,
            link: url,
            caption: caption.value.trim(),
            align: currentAlign(),
            width: positiveInt(width.value),
            height: positiveInt(height.value),
            size: option?.dataset.url
                ? { name: option.value, url: option.dataset.url }
                : null,
        });

        modal.hide();
    });

    element.addEventListener('hidden.bs.modal', () => {
        if (!settled) resolve(null);
    });

    element.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.matches('input:not([type="checkbox"])')) {
            event.preventDefault();
            element.querySelector('[data-details-apply]').click();
        }
    });

    return {
        async open(settings) {
            settled = false;
            element.dataset.mediaId = settings.mediaId ? String(settings.mediaId) : '';

            preview.src = settings.src ?? '';
            preview.alt = settings.alt ?? '';
            filename.textContent = settings.filename ?? '';

            alt.value = settings.alt ?? '';
            link.value = settings.link ?? '';
            setOpenLink(settings.link ?? '');
            caption.value = settings.caption ?? '';
            setAlign(settings.align ?? 'none');

            width.value = settings.width ? String(settings.width) : '';
            height.value = settings.height ? String(settings.height) : '';
            ratio.checked = true;
            aspect =
                settings.width && settings.height
                    ? settings.width / settings.height
                    : settings.naturalWidth && settings.naturalHeight
                      ? settings.naturalWidth / settings.naturalHeight
                      : null;

            resetSizes();
            modal.show();

            const promise = new Promise((resolve) => {
                resolver = resolve;
            });

            loadSizes(settings.mediaId, settings.src);

            return promise;
        },
    };
}

function positiveInt(value) {
    const number = Number.parseInt(value, 10);

    return Number.isFinite(number) && number > 0 ? number : null;
}
