import { Modal } from 'bootstrap';
import { http } from './http.js';
import { notify } from './toast.js';
import { busy, debounce } from './busy.js';

/**
 * Sélecteur d'image branché sur la médiathèque WordPress.
 *
 * `open()` renvoie une promesse résolue avec le média choisi, ou `null` si
 * l'utilisateur ferme la fenêtre.
 */
export function createMediaPicker() {
    const element = document.getElementById('ag-media-modal');

    if (!element) {
        return { open: async () => null };
    }

    const modal = new Modal(element);
    const grid = element.querySelector('[data-media-grid]');
    const search = element.querySelector('[data-media-search]');
    const confirm = element.querySelector('[data-media-confirm]');
    const upload = element.querySelector('[data-media-upload]');
    const uploadButton = element.querySelector('[data-media-upload-trigger]');
    const indexUrl = element.dataset.indexUrl;
    const storeUrl = element.dataset.storeUrl;

    let selected = null;
    let resolver = null;
    let loaded = false;

    function select(media, tile) {
        selected = media;
        grid.querySelectorAll('.ag-media-tile').forEach((node) => node.classList.remove('is-selected'));
        tile?.classList.add('is-selected');
        confirm.disabled = false;
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

    return {
        async open() {
            selected = null;
            confirm.disabled = true;

            if (!loaded) {
                loaded = true;
                await load();
            }

            modal.show();

            return new Promise((resolve) => {
                resolver = resolve;
            });
        },
    };
}
