/**
 * Utilitaires d'adresses partagés par l'éditeur et ses fenêtres.
 */

/**
 * Adresse http(s) valide, chaîne vide si rien n'est saisi, `null` sinon.
 *
 * Le `null` distingue « pas de lien » d'une saisie erronée : l'appelant peut
 * ainsi refuser la seconde sans effacer silencieusement un lien existant.
 */
export function safeUrl(value) {
    const trimmed = String(value ?? '').trim();

    if (!trimmed) return '';

    try {
        const url = new URL(trimmed);

        return ['http:', 'https:'].includes(url.protocol) ? trimmed : null;
    } catch {
        return null;
    }
}

/** Nom de fichier lisible extrait d'une adresse. */
export function fileNameOf(src) {
    try {
        return decodeURIComponent(new URL(src, window.location.origin).pathname.split('/').pop() ?? src);
    } catch {
        return src;
    }
}

/** Compare deux adresses en ignorant le protocole et les paramètres. */
export function sameUrl(a, b) {
    const normalize = (value) => {
        try {
            const url = new URL(value, window.location.origin);

            return url.host + url.pathname;
        } catch {
            return String(value ?? '');
        }
    };

    return Boolean(a) && Boolean(b) && normalize(a) === normalize(b);
}
