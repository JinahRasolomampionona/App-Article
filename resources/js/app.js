import * as bootstrap from 'bootstrap';

import { initArticlesTable } from './modules/articles-table.js';
import { initEditor } from './modules/editor.js';
import { initSites, initSiteSwitcher, initQuickSync, initSiteAudit } from './modules/sites.js';
import { initDonuts } from './modules/donut.js';
import { initPasswordToggles } from './modules/password-toggle.js';
import { notify } from './modules/toast.js';

// Certaines vues instancient des composants Bootstrap (tooltips, modals) ;
// l'objet est donc exposé plutôt que ré-importé partout.
window.bootstrap = bootstrap;

document.addEventListener('DOMContentLoaded', () => {
    initSiteSwitcher();
    initSites();
    initQuickSync();
    initSiteAudit();
    initArticlesTable();
    initEditor();
    initDonuts();
    initPasswordToggles();

    // Messages flash Laravel relayés dans le système de toasts.
    document.querySelectorAll('[data-flash]').forEach((element) => {
        notify[element.dataset.flash === 'error' ? 'error' : 'success'](element.textContent.trim());
        element.remove();
    });

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        new bootstrap.Tooltip(element);
    });

    // Confirmation avant une suppression : l'action est irréversible.
    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });
});
