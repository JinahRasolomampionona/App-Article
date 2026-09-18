/**
 * Met un bouton dans un état « en cours » : désactivé, avec un spinner, sans
 * jamais laisser croire que l'application est figée.
 */
export function busy(button, label = null) {
    if (!button) {
        return () => {};
    }

    const original = button.innerHTML;
    const wasDisabled = button.disabled;

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = `<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>${
        label ?? button.dataset.busyLabel ?? 'En cours…'
    }`;

    return () => {
        button.disabled = wasDisabled;
        button.removeAttribute('aria-busy');
        button.innerHTML = original;
    };
}

/**
 * Debounce simple, utilisé notamment par la recherche d'articles.
 */
export function debounce(fn, wait = 350) {
    let timer = null;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}
