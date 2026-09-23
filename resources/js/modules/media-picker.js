import { Modal } from 'bootstrap';
import { http } from './http.js';
import { notify } from './toast.js';
import { busy, debounce } from './busy.js';
import { fileNameOf } from './url.js';

/**
 * Sélecteur d'image branché sur la médiathèque WordPress.
 *
 * `open()` renvoie une promesse résolue avec le média choisi, ou `null` si
 * l'utilisateur ferme la fenêtre.
 *
 * Le panneau latéral reprend « Détails du fichier joint » de WordPress : les
 * champs texte alternatif, titre, légende et description appartiennent au
 * média et sont enregistrés dans la médiathèque, donc valables partout où
 * l'image est utilisée.
 */
export function createMediaPicker() {
    const element = document.getElementById('ag-media-modal');

    if (!element) {
        return { open: async () => null, indexUrl: null };
    }

    const modal = new Modal(element);
    const grid = element.querySelector('[data-media-grid]');
    const search = element.querySelector('[data-media-search]');
    const confirm = element.querySelector('[data-media-confirm]');
    const upload = element.querySelector('[data-media-upload]');
    const uploadButton = element.querySelector('[data-media-upload-trigger]');
    const indexUrl = element.dataset.indexUrl;
    const storeUrl = element.dataset.storeUrl;
    const readOnly = element.dataset.readOnly === '1';

    const details = {
        body: element.querySelector('[data-media-details-body]'),
        empty: element.querySelector('[data-media-details-empty]'),
        thumb: element.querySelector('[data-media-details-thumb]'),
        filename: element.querySelector('[data-media-details-filename]'),
        dimensions: element.querySelector('[data-media-details-dimensions]'),
        url: element.querySelector('[data-media-details-url]'),
        save: element.querySelector('[data-media-details-save]'),
        note: element.querySelector('[data-media-details-note]'),
        fields: Object.fromEntries(
            Array.from(element.querySelectorAll('[data-media-field]')).map((input) => [
                input.dataset.mediaField,
                input,
            ]),
        ),
    };

    let selected = null;
    let resolver = null;
    let loaded = false;

    /* --- Détails du fichier joint ------------------------------------------- */

    function showDetails(media) {
        if (!details.body) return;

        details.body.hidden = !media;
        if (details.empty) details.empty.hidden = Boolean(media);

        if (!media) return;

        details.thumb.src = media.thumbnail || media.url;
        details.thumb.alt = media.alt || '';
        details.filename.textContent = media.filename || fileNameOf(media.url);
        details.dimensions.textContent =
            media.width && media.height ? `${media.width} × ${media.height} px` : '';
        details.url.value = media.url ?? '';

        details.fields.alt_text.value = media.alt ?? '';
        details.fields.title.value = media.title ?? '';
        details.fields.caption.value = media.caption ?? '';
        details.fields.description.value = media.description ?? '';

        // Sans identifiants d'écriture, WordPress refuserait l'enregistrement :
        // les champs restent lisibles mais ne laissent pas espérer une sauvegarde.
        Object.values(details.fields).forEach((input) => {
            input.readOnly = readOnly;
        });

        if (details.save) details.save.hidden = readOnly;
        if (details.note && readOnly) {
            details.note.textContent =
                'Ajoutez une Application Password au site pour modifier ces informations.';
        }
    }

    details.save?.addEventListener('click', async () => {
        if (!selected?.id) return;

        const done = busy(details.save, 'Enregistrement…');

        try {
            const data = await http.put(`${indexUrl}/${selected.id}`, {
                alt_text: details.fields.alt_text.value,
                title: details.fields.title.value,
                caption: details.fields.caption.value,
                description: details.fields.description.value,
            });

            selected = { ...selected, ...data.media };
            showDetails(selected);
            refreshTile(selected);
            notify.success(data.message);
        } catch (error) {
            notify.error(error.message);
        } finally {
            done();
        }
    });

    element.querySelector('[data-media-copy-url]')?.addEventListener('click', async () => {
        if (!details.url?.value) return;

        try {
            await navigator.clipboard.writeText(details.url.value);
            notify.success('URL copiée dans le presse-papiers.');
        } catch {
            // Presse-papiers refusé (contexte non sécurisé) : la sélection
            // manuelle reste possible.
            details.url.select();
        }
    });

    /* --- Grille -------------------------------------------------------------- */

    function select(media, tile) {
        selected = media;
        grid.querySelectorAll('.ag-media-tile').forEach((node) => node.classList.remove('is-selected'));
        tile?.classList.add('is-selected');
        confirm.disabled = false;
        showDetails(media);
    }

    /** Répercute une modification de détails sur la vignette sélectionnée. */
    function refreshTile(media) {
        const tile = grid.querySelector('.ag-media-tile.is-selected');

        if (!tile) return;

        tile.title = media.title || media.alt || media.url;
        tile.setAttribute('aria-label', media.title || media.alt || 'Image sans titre');
        const img = tile.querySelector('img');
        if (img) img.alt = media.alt || '';
    }

    async function load(term = '') {
        grid.innerHTML = Array.from({ length: 8 })
            .map(() => '<div class="ag-media-tile"><div class="ag-skeleton h-100"></div></div>')
            .join('');

        try {
            const params = new URLSearchParams();
            if (term) params.set('search', term);

            const data = await http.get(`${indexUrl}?${params.toString()}`);

            if (!data.items.length) {
                grid.innerHTML =
                    '<p class="ag-muted mb-0" style="grid-column:1/-1">Aucune image trouvée dans la médiathèque.</p>';
                return;
            }

            grid.innerHTML = '';

            data.items.forEach((media) => {
                const tile = document.createElement('button');
                tile.type = 'button';
                tile.className = 'ag-media-tile';
                tile.dataset.mediaId = String(media.id);
                tile.title = media.title || media.alt || media.url;
                tile.setAttribute('aria-label', media.title || media.alt || 'Image sans titre');

                const img = document.createElement('img');
                img.src = media.thumbnail || media.url;
                img.alt = media.alt || '';
                img.loading = 'lazy';

                tile.appendChild(img);
                tile.addEventListener('click', () => select(media, tile));
                grid.appendChild(tile);
            });
        } catch (error) {
            grid.innerHTML = `<p class="text-danger mb-0" style="grid-column:1/-1">${error.message}</p>`;
        }
    }

    search?.addEventListener(
        'input',
        debounce(() => load(search.value.trim()), 350),
    );

    uploadButton?.addEventListener('click', () => upload.click());

    upload?.addEventListener('change', async () => {
        const file = upload.files?.[0];
        if (!file) return;

        const done = busy(uploadButton, 'Envoi…');
        const data = new FormData();
        data.append('file', file);

        try {
            const result = await http.post(storeUrl, data);
            notify.success(result.message);
            upload.value = '';
            await load();
            selected = result.media;
            confirm.disabled = false;
            showDetails(selected);
        } catch (error) {
            notify.error(error.message);
        } finally {
            done();
        }
    });

    confirm?.addEventListener('click', () => {
        modal.hide();
        resolver?.(selected);
        resolver = null;
    });

    element.addEventListener('hidden.bs.modal', () => {
        resolver?.(null);
        resolver = null;
    });

    /**
     * Présélectionne un média déjà utilisé par l'article : ouvrir « Détails »
     * depuis l'image mise en avant doit afficher ses champs sans que
     * l'utilisateur ait à la retrouver dans la grille.
     */
    async function preselect(mediaId) {
        const tile = Array.from(grid.querySelectorAll('.ag-media-tile')).find(
            (node) => node.dataset.mediaId === String(mediaId),
        );

        if (tile) {
            tile.click();
            tile.scrollIntoView({ block: 'nearest' });
            return;
        }

        try {
            const data = await http.get(`${indexUrl}/${mediaId}`);
            select(data.media, null);
        } catch {
            // Média absent de la médiathèque : la grille reste utilisable.
        }
    }

    return {
        indexUrl,
        async open({ mediaId = null } = {}) {
            selected = null;
            confirm.disabled = true;
            showDetails(null);

            if (!loaded) {
                loaded = true;
                await load();
            }

            modal.show();

            // La promesse est armée avant la présélection : un clic sur
            // « Utiliser cette image » pendant la requête ne doit pas se perdre.
            const promise = new Promise((resolve) => {
                resolver = resolve;
            });

            if (mediaId) {
                preselect(mediaId);
            }

            return promise;
        },
    };
}
