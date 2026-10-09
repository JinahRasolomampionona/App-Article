import { Modal } from 'bootstrap';
import { http } from './http.js';
import { notify } from './toast.js';
import { busy, debounce } from './busy.js';
import { fileNameOf } from './url.js';
import { bindDropzone, pickFile, rejectReason } from './dropzone.js';
import { shrinkImage } from './image-resize.js';

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
        return { open: async () => null, indexUrl: null, canUpload: false, upload: async () => null };
    }

    const modal = new Modal(element);
    const grid = element.querySelector('[data-media-grid]');
    const search = element.querySelector('[data-media-search]');
    const confirm = element.querySelector('[data-media-confirm]');
    const uploadButtons = element.querySelectorAll('[data-media-upload-trigger]');
    const dropzone = element.querySelector('[data-media-dropzone]');
    const dropzoneTitle = element.querySelector('[data-media-dropzone-title]');
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
    // Dernière version connue de chaque média de la grille : une vignette ne
    // doit pas resservir les détails d'avant un enregistrement.
    const known = new Map();

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

    const fieldKeys = { alt_text: 'alt', title: 'title', caption: 'caption', description: 'description' };

    /** Vrai si un champ du panneau diffère de ce qui est enregistré sur WordPress. */
    function detailsDirty() {
        if (!selected?.id || readOnly || !details.body || details.body.hidden) return false;

        return Object.entries(fieldKeys).some(
            ([field, key]) => (details.fields[field]?.value ?? '') !== (selected[key] ?? ''),
        );
    }

    /** Enregistre les détails sur WordPress ; renvoie faux en cas d'échec. */
    async function saveDetails(button) {
        if (!selected?.id) return false;

        const done = busy(button, 'Enregistrement…');

        try {
            const data = await http.put(`${indexUrl}/${selected.id}`, {
                alt_text: details.fields.alt_text.value,
                title: details.fields.title.value,
                caption: details.fields.caption.value,
                description: details.fields.description.value,
            });

            selected = { ...selected, ...data.media };
            known.set(String(selected.id), selected);
            showDetails(selected);
            refreshTile(selected);
            notify.success(data.message);
            return true;
        } catch (error) {
            notify.error(error.message);
            return false;
        } finally {
            done();
        }
    }

    details.save?.addEventListener('click', () => saveDetails(details.save));

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
            data.items.forEach((media) => grid.appendChild(createTile(media)));
        } catch (error) {
            grid.innerHTML = `<p class="text-danger mb-0" style="grid-column:1/-1">${error.message}</p>`;
        }
    }

    function createTile(media) {
        known.set(String(media.id), media);

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
        tile.addEventListener('click', () => select(known.get(String(media.id)) ?? media, tile));

        return tile;
    }

    search?.addEventListener(
        'input',
        debounce(() => load(search.value.trim()), 350),
    );

    /* --- Onglets « Téléverser des fichiers » / « Médiathèque » ------------- */

    function showTab(name) {
        element.querySelectorAll('[data-media-tab]').forEach((tab) => {
            const active = tab.dataset.mediaTab === name;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        element.querySelectorAll('[data-media-pane]').forEach((pane) => {
            pane.hidden = pane.dataset.mediaPane !== name;
        });
    }

    element.querySelectorAll('[data-media-tab]').forEach((tab) =>
        tab.addEventListener('click', () => showTab(tab.dataset.mediaTab)),
    );

    /* --- Téléversement (bouton ou glisser-déposer) -------------------------- */

    /**
     * Envoie un fichier dans la médiathèque WordPress et renvoie le média
     * créé. Partagé avec l'éditeur (dépôt sur l'image à la une ou sur une
     * image du contenu).
     *
     * `onStatus(texte)` décrit l'étape en cours : sur une connexion lente,
     * l'utilisateur voit que l'envoi avance au lieu d'un écran figé.
     */
    async function uploadFile(file, { onStatus } = {}) {
        const reason = rejectReason(file);
        if (reason) throw new Error(reason);

        onStatus?.('Préparation de l’image…');

        const data = new FormData();
        data.append('file', await shrinkImage(file));

        const result = await http.upload(storeUrl, data, {
            onProgress: (ratio) =>
                onStatus?.(
                    ratio < 1
                        ? `Envoi… ${Math.round(ratio * 100)} %`
                        : 'Création des miniatures par WordPress…',
                ),
        });
        // La grille sera rechargée à la prochaine ouverture.
        loaded = false;

        return result;
    }

    // Un second dépôt pendant un envoi lent créerait un doublon dans la
    // médiathèque.
    let uploading = false;

    async function uploadInModal(file) {
        if (!file || readOnly || uploading) return;

        const reason = rejectReason(file);
        if (reason) {
            notify.error(reason);
            return;
        }

        uploading = true;
        const restoreTitle = dropzoneTitle?.textContent;
        dropzone?.classList.add('is-busy');
        if (dropzoneTitle) dropzoneTitle.textContent = `Téléversement de « ${file.name} »…`;
        const done = Array.from(uploadButtons).map((button) => busy(button, 'Envoi…'));

        try {
            const result = await uploadFile(file, {
                onStatus: (text) => {
                    if (dropzoneTitle) dropzoneTitle.textContent = `${file.name} — ${text}`;
                },
            });
            notify.success(result.message);

            // Comme WordPress : retour à la médiathèque, nouvelle image
            // sélectionnée, prête à être utilisée. La vignette est ajoutée en
            // tête de grille : recharger toute la médiathèque coûterait un
            // aller-retour WordPress de plus.
            showTab('library');
            const tile = createTile(result.media);

            if (grid.querySelector('.ag-media-tile[data-media-id]')) {
                grid.prepend(tile);
            } else {
                grid.innerHTML = '';
                grid.appendChild(tile);
            }

            loaded = true;
            select(result.media, tile);
            tile.scrollIntoView({ block: 'nearest' });
        } catch (error) {
            notify.error(error.message);
        } finally {
            uploading = false;
            done.forEach((restore) => restore());
            dropzone?.classList.remove('is-busy');
            if (dropzoneTitle) dropzoneTitle.textContent = restoreTitle;
        }
    }

    uploadButtons.forEach((button) =>
        button.addEventListener('click', async () => uploadInModal(await pickFile())),
    );

    // Toute la fenêtre accepte un dépôt, quel que soit l'onglet affiché.
    bindDropzone(element.querySelector('[data-media-body]'), {
        enabled: () => !readOnly && Boolean(storeUrl) && Boolean(dropzone),
        onDrop: (file) => uploadInModal(file),
    });

    confirm?.addEventListener('click', async () => {
        // Titre ou texte alternatif saisi sans cliquer « Enregistrer » : on
        // l'enregistre avant d'utiliser l'image, sinon il serait perdu.
        if (detailsDirty() && !(await saveDetails(confirm))) return;

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
        canUpload: !readOnly && Boolean(storeUrl),
        upload: uploadFile,
        async open({ mediaId = null, tab = 'library' } = {}) {
            selected = null;
            confirm.disabled = true;
            showDetails(null);
            showTab(readOnly ? 'library' : tab);

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
