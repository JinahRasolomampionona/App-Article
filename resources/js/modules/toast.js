/**
 * Notifications discrètes, en bas à droite.
 */

const ICONS = {
    success: 'bi-check-circle-fill',
    danger: 'bi-exclamation-octagon-fill',
    warning: 'bi-exclamation-triangle-fill',
    info: 'bi-info-circle-fill',
};

function container() {
    let element = document.querySelector('.ag-toasts');

    if (!element) {
        element = document.createElement('div');
        element.className = 'ag-toasts';
        // Les messages sont annoncés sans voler le focus de l'utilisateur.
        element.setAttribute('aria-live', 'polite');
        element.setAttribute('aria-atomic', 'true');
        document.body.appendChild(element);
    }

    return element;
}

export function toast(message, variant = 'info', timeout = 4500) {
    const element = document.createElement('div');
    element.className = `ag-toast ag-toast--${variant}`;
    element.setAttribute('role', variant === 'danger' ? 'alert' : 'status');

    const icon = document.createElement('i');
    icon.className = `bi ${ICONS[variant] ?? ICONS.info} ag-toast__icon`;
    icon.setAttribute('aria-hidden', 'true');

    const text = document.createElement('div');
    text.textContent = message;

    element.append(icon, text);
    container().appendChild(element);

    const remove = () => {
        element.style.transition = 'opacity 180ms ease';
        element.style.opacity = '0';
        setTimeout(() => element.remove(), 200);
    };

    const timer = setTimeout(remove, timeout);

    element.addEventListener('click', () => {
        clearTimeout(timer);
        remove();
    });

    return element;
}

export const notify = {
    success: (message) => toast(message, 'success'),
    error: (message) => toast(message, 'danger', 7000),
    warning: (message) => toast(message, 'warning'),
    info: (message) => toast(message, 'info'),
};
