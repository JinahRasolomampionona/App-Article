import { Modal } from 'bootstrap';
import { http } from './http.js';

/**
 * Page Audits, onglet « Historique des scans » : le bouton « Voir » ouvre la
 * liste de tous les scans de l'article (date, origine, résultat).
 */
export function initAuditHistory() {
    const element = document.getElementById('ag-scans-modal');

    if (!element) {
        return;
    }

    const modal = new Modal(element);
    const list = element.querySelector('[data-scans-list]');
    const title = element.querySelector('[data-scans-title]');
    const count = element.querySelector('[data-scans-count]');

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-scans-url]');

        if (!button) return;

        event.preventDefault();

        title.textContent = '';
        count.textContent = '';
        list.innerHTML = '<div class="ag-skeleton mb-2"></div><div class="ag-skeleton mb-2"></div><div class="ag-skeleton w-75"></div>';
        modal.show();

        try {
            const data = await http.get(button.dataset.scansUrl);
            title.textContent = data.title;
            count.textContent = `(${data.total})`;
            // HTML rendu et échappé côté serveur (Blade).
            list.innerHTML = data.html;
        } catch (error) {
            const message = document.createElement('p');
            message.className = 'text-danger mb-0';
            message.textContent = error.message;
            list.replaceChildren(message);
        }
    });
}
