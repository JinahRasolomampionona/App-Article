/**
 * Glisser-déposer d'images, à la manière de WordPress.
 *
 * `bindDropzone(container, { selector, onDrop })` : la zone (ou chacun de ses
 * descendants qui correspond à `selector`, par délégation — utile pour une
 * liste re-rendue) reçoit `is-dragover` pendant le survol et appelle
 * `onDrop(file, element)` avec la première image déposée.
 */

export const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
export const MAX_BYTES = 10 * 1024 * 1024;

/** Message d'erreur lisible, ou `null` si le fichier est acceptable. */
export function rejectReason(file) {
    if (!file) return 'Aucun fichier reçu.';
    if (!ACCEPTED_TYPES.includes(file.type)) {
        return `« ${file.name} » n’est pas une image acceptée (JPG, PNG, GIF ou WebP).`;
    }
    if (file.size > MAX_BYTES) {
        return `« ${file.name} » dépasse 10 Mo.`;
    }
    return null;
}

function carriesFiles(event) {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

export function bindDropzone(container, { selector = null, onDrop, enabled = () => true }) {
    if (!container) return;

    let current = null;

    const targetOf = (event) => (selector ? event.target.closest(selector) : container);

    const clear = () => {
        current?.classList.remove('is-dragover');
        current = null;
    };

    container.addEventListener('dragover', (event) => {
        if (!carriesFiles(event) || !enabled()) return;

        const target = targetOf(event);
        if (!target || !container.contains(target)) return;

        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';

        if (current !== target) {
            clear();
            current = target;
            current.classList.add('is-dragover');
        }
    });

    container.addEventListener('dragleave', (event) => {
        if (current && !current.contains(event.relatedTarget)) clear();
    });

    container.addEventListener('drop', (event) => {
        if (!carriesFiles(event) || !enabled()) return;

        const target = targetOf(event);
        clear();
        if (!target) return;

        event.preventDefault();
        event.stopPropagation();

        const files = Array.from(event.dataTransfer.files ?? []);
        const file = files.find((candidate) => candidate.type.startsWith('image/')) ?? files[0];

        onDrop(file, target);
    });
}

/**
 * Un fichier lâché à côté d'une zone ne doit pas faire quitter la page (le
 * navigateur ouvrirait l'image à la place de l'éditeur, modifications perdues).
 */
export function preventStrayDrops() {
    ['dragover', 'drop'].forEach((type) =>
        window.addEventListener(type, (event) => {
            if (carriesFiles(event) && !event.defaultPrevented) {
                event.preventDefault();
                if (event.dataTransfer) event.dataTransfer.dropEffect = 'none';
            }
        }),
    );
}

/** Ouvre le sélecteur de fichiers du système et résout avec le fichier choisi. */
export function pickFile() {
    return new Promise((resolve) => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = ACCEPTED_TYPES.join(',');
        input.addEventListener('change', () => resolve(input.files?.[0] ?? null), { once: true });
        input.click();
    });
}
