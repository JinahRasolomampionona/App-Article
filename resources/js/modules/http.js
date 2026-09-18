/**
 * Enveloppe minimale autour de `fetch`.
 *
 * Elle ajoute systématiquement le jeton CSRF de Laravel et normalise les
 * erreurs : l'appelant reçoit toujours un message affichable, jamais une
 * exception réseau brute.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

export class HttpError extends Error {
    constructor(message, status, payload) {
        super(message);
        this.name = 'HttpError';
        this.status = status;
        this.payload = payload;
    }
}

async function request(url, { method = 'GET', body = null, headers = {}, signal } = {}) {
    const options = {
        method,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...headers,
        },
    };

    if (body instanceof FormData) {
        options.body = body;
    } else if (body !== null) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    let response;

    try {
        response = await fetch(url, options);
    } catch (error) {
        if (error.name === 'AbortError') {
            throw error;
        }

        throw new HttpError(
            'Connexion au serveur impossible. Vérifiez votre réseau puis réessayez.',
            0,
            null,
        );
    }

    let payload = null;

    if (response.status !== 204) {
        const text = await response.text();

        try {
            payload = text ? JSON.parse(text) : null;
        } catch {
            payload = null;
        }
    }

    if (!response.ok) {
        // Laravel renvoie `errors` pour une validation, `message` sinon.
        const firstValidationError = payload?.errors
            ? Object.values(payload.errors).flat()[0]
            : null;

        throw new HttpError(
            firstValidationError ||
                payload?.message ||
                "Une erreur est survenue. L'action n'a pas été effectuée.",
            response.status,
            payload,
        );
    }

    return payload;
}

export const http = {
    get: (url, options) => request(url, { ...options, method: 'GET' }),
    post: (url, body, options) => request(url, { ...options, method: 'POST', body }),
    put: (url, body, options) => request(url, { ...options, method: 'PUT', body }),
    delete: (url, options) => request(url, { ...options, method: 'DELETE' }),
};
