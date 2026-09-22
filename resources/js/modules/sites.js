import { http } from './http.js';
import { notify } from './toast.js';
import { busy } from './busy.js';

/**
 * Page « Sites WordPress » : test de connexion, synchronisation et suivi de
 * progression sans recharger la page.
 */
export function initSites() {
    const root = document.getElementById('ag-sites');

    if (!root) {
        return;
    }

    // Synchronisation déjà en cours au chargement (lancée depuis une autre
    // page ou par `wp:sync`) : on la suit pour mettre la carte à jour.
    root.querySelectorAll('[data-sync-progress][data-pending="1"]').forEach((progress) => {
        const row = progress.closest('[data-site-row]');
        const button = row?.querySelector('[data-sync-url]');
        watch(row, button, busy(button, 'En cours…'));
    });

    root.addEventListener('click', async (event) => {
        const testButton = event.target.closest('[data-test-url]');
        const syncButton = event.target.closest('[data-sync-url]');

        if (testButton) {
            const done = busy(testButton, 'Test…');

            try {
                const data = await http.post(testButton.dataset.testUrl, {});
                updateStatus(testButton.closest('[data-site-row]'), data);
                notify.success(data.message);
            } catch (error) {
                const row = testButton.closest('[data-site-row]');
                updateStatus(row, error.payload ?? {});
                notify.error(error.message);
            } finally {
                done();
            }
        }

        if (syncButton) {
            const row = syncButton.closest('[data-site-row]');
            const done = busy(syncButton, 'Lancement…');

            try {
                const data = await http.post(syncButton.dataset.syncUrl, {});
                (data.already_running ? notify.info : notify.success)(data.message);
                watch(row, syncButton, done);
            } catch (error) {
                notify.error(error.message);
                done();
            }
        }
    });

    function updateStatus(row, data) {
        if (!row || !data.status_label) return;

        const badge = row.querySelector('[data-status-badge]');

        if (badge) {
            badge.textContent = data.status_label;
            badge.className = `ag-badge ag-badge--${data.status_variant ?? 'muted'}`;
        }

        const checked = row.querySelector('[data-checked-at]');
        if (checked && data.checked_at) {
            checked.textContent = data.checked_at;
        }
    }

    /**
     * Sondage de la progression : espacé de 2,5 s et borné dans le temps pour
     * ne pas solliciter le serveur indéfiniment.
     */
    function watch(row, button, releaseButton) {
        const statusUrl = row?.dataset.statusUrl;
        if (!statusUrl) {
            releaseButton();
            return;
        }

        const progress = row.querySelector('[data-sync-progress]');
        const stalled = row.querySelector('[data-sync-stalled]');
        if (progress) progress.hidden = false;
        if (stalled) stalled.hidden = true;

        let attempts = 0;
        let stopped = false;

        function stop({ stalledQueue = false, keepProgress = false } = {}) {
            if (stopped) return;
            stopped = true;

            clearInterval(timer);
            if (progress) progress.hidden = keepProgress;
            if (stalled) stalled.hidden = !stalledQueue;
            releaseButton();
        }

        const timer = setInterval(async () => {
            attempts += 1;

            try {
                const data = await http.get(statusUrl);

                const counter = row.querySelector('[data-articles-count]');
                if (counter) counter.textContent = data.articles_count;

                const categories = row.querySelector('[data-categories-count]');
                if (categories) categories.textContent = data.categories_count;

                // File d'attente sans worker : inutile de sonder pendant
                // quatre minutes un travail que personne n'exécutera. Un site
                // « running » est déjà traité (worker ou `wp:sync`) : on attend.
                if (data.queue_stalled && data.sync_status === 'queued') {
                    stop({ stalledQueue: true });
                    notify.error(data.queue_warning ?? 'Aucun worker ne traite la file d’attente.');
                    return;
                }

                if (data.sync_status === 'idle' || data.sync_status === 'failed') {
                    stop();

                    const lastSync = row.querySelector('[data-last-sync]');
                    if (lastSync && data.last_sync_at) lastSync.textContent = data.last_sync_at;

                    updateStatus(row, data);

                    if (data.sync_status === 'failed') {
                        notify.error(data.sync_message ?? 'La synchronisation a échoué.');
                    } else {
                        notify.success(`Synchronisation terminée : ${data.articles_count} article(s).`);
                    }
                }
            } catch {
                // Une erreur ponctuelle de sondage ne doit pas casser le suivi.
            }

            // ~4 minutes de suivi maximum. Un gros site peut prendre plus
            // longtemps : le message reste affiché, le bouton est rendu.
            if (attempts > 96) {
                stop({ keepProgress: true });
            }
        }, 2500);
    }
}

/**
 * Bouton « Synchroniser » présent hors de la page Sites (en-tête des articles).
 *
 * Le travail réel est fait par la file d'attente : on se contente de le
 * déclencher et de rendre la main immédiatement.
 */
export function initQuickSync() {
    if (document.getElementById('ag-sites')) {
        // La page Sites a son propre suivi de progression.
        return;
    }

    // Délégation : le bouton de l'état vide du tableau est réinjecté par AJAX.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('#ag-sync-current, #ag-sync-empty');

        if (!button) {
            return;
        }

        const done = busy(button, 'Lancement…');

        try {
            const data = await http.post(button.dataset.syncUrl, {});
            notify.success(data.message);

            if (data.queue_warning) {
                notify.error(data.queue_warning);
            }
        } catch (error) {
            notify.error(error.message);
        } finally {
            done();
        }
    });
}

/**
 * Bouton « Auditer tout le site » de la page Audits.
 */
export function initSiteAudit() {
    const button = document.getElementById('ag-run-site-audit');

    if (!button) {
        return;
    }

    button.addEventListener('click', async () => {
        const done = busy(button, 'Programmation…');

        try {
            const data = await http.post(button.dataset.url, {});
            notify.success(data.message);
        } catch (error) {
            notify.error(error.message);
        } finally {
            done();
        }
    });
}

/**
 * Sélecteur de site du header : mémorise le choix puis recharge la page.
 *
 * La page est rechargée sans sa query string. Les filtres du tableau
 * (recherche, catégories, pagination) y sont écrits au fil des interactions :
 * les conserver appliquerait au nouveau site la recherche et surtout les
 * identifiants de catégories de l'ancien, qui n'y existent pas — le tableau
 * s'afficherait vide alors que le site contient des articles.
 */
export function initSiteSwitcher() {
    document.querySelectorAll('[data-select-site]').forEach((element) => {
        element.addEventListener('click', async (event) => {
            event.preventDefault();

            try {
                await http.post(element.dataset.selectSite, {});

                const redirect = element.closest('[data-select-redirect]')?.dataset.selectRedirect;
                window.location.assign(redirect || window.location.pathname);
            } catch (error) {
                notify.error(error.message);
            }
        });
    });
}
