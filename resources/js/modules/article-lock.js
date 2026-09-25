import { http } from './http.js';
import { notify } from './toast.js';
import { busy } from './busy.js';

/**
 * Prise en charge de l'article dans l'éditeur.
 *
 * - détenteur : heartbeat régulier pour garder le verrou, « Libérer » et
 *   « Terminer la correction » (enregistre, relance l'audit, libère) ;
 * - autre agent / disponible : consultation, « Prendre l'article ».
 *
 * Tout est revérifié par le serveur ; si le verrou est perdu (expiré,
 * libéré par un Admin), l'éditeur passe en consultation seule.
 */
export function initArticleLock({ isDirty, save, setReadOnly }) {
    const panel = document.getElementById('ag-lock');

    if (!panel) return;

    const lostBox = panel.querySelector('[data-lock-lost]');
    let heartbeatTimer = null;
    let finishing = false;

    function lost(message) {
        clearInterval(heartbeatTimer);
        panel.dataset.state = 'lost';

        panel.querySelector('[data-lock-actions]')?.setAttribute('hidden', '');
        panel.querySelector('[data-lock-info]')?.setAttribute('hidden', '');

        if (lostBox) {
            lostBox.textContent = message;
            lostBox.hidden = false;
        }

        setReadOnly();
        notify.error(message);
    }

    document.addEventListener('ag:lock-lost', (event) => lost(event.detail));

    /* --- Heartbeat ------------------------------------------------------------ */

    if (panel.dataset.state === 'mine') {
        const every = Math.max(15, Number(panel.dataset.heartbeatSeconds || 60)) * 1000;

        const beat = async () => {
            try {
                await http.post(panel.dataset.heartbeatUrl, {});
            } catch (error) {
                // 409 : le verrou n'est plus à nous. Une erreur réseau
                // passagère, elle, sera rattrapée au battement suivant.
                if (error.status === 409 || error.status === 403) {
                    lost(error.message);
                }
            }
        };

        heartbeatTimer = setInterval(beat, every);

        // Retour sur l'onglet après une mise en veille : on vérifie tout de
        // suite plutôt que d'attendre le prochain battement.
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && panel.dataset.state === 'mine') beat();
        });

        window.addEventListener('beforeunload', (event) => {
            if (!finishing && isDirty()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    /* --- Prendre -------------------------------------------------------------- */

    panel.querySelector('[data-take]')?.addEventListener('click', async (event) => {
        const done = busy(event.currentTarget, 'Prise en charge…');

        try {
            await http.post(panel.dataset.takeUrl, {});
            // L'éditeur se recharge en mode modification, verrou posé.
            window.location.reload();
        } catch (error) {
            done();
            notify.error(error.message);
        }
    });

    /* --- Libérer -------------------------------------------------------------- */

    panel.querySelector('[data-release]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const confirmMessage =
            button.dataset.confirmMessage ||
            (isDirty() ? 'Des modifications ne sont pas enregistrées sur WordPress. Libérer quand même l’article ?' : null);

        if (confirmMessage && !window.confirm(confirmMessage)) return;

        const done = busy(button, 'Libération…');

        try {
            const data = await http.post(panel.dataset.releaseUrl, {});
            finishing = true;
            notify.success(data.message);
            window.location.href = panel.dataset.indexUrl;
        } catch (error) {
            done();
            notify.error(error.message);
        }
    });

    /* --- Terminer la correction ---------------------------------------------- */

    panel.querySelector('[data-finish]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;

        // 1. Enregistrer ce qui ne l'est pas encore.
        if (isDirty()) {
            const saved = await save(button);
            if (!saved) return;
        }

        // 2-6. Audit complet, activité, statistiques, libération : côté serveur.
        const done = busy(button, 'Audit et clôture…');

        try {
            const data = await http.post(panel.dataset.finishUrl, {});
            finishing = true;
            clearInterval(heartbeatTimer);

            if (data.issues_count > 0) {
                notify.error(data.message);
            } else {
                notify.success(data.message);
            }

            // Laisser le temps de lire le résultat avant de revenir à la liste.
            setTimeout(() => {
                window.location.href = data.redirect || panel.dataset.indexUrl;
            }, 1600);
        } catch (error) {
            done();

            if (error.status === 409) {
                lost(error.message);
                return;
            }

            notify.error(error.message);
        }
    });
}
