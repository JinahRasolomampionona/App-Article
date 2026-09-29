import { Modal, Tooltip } from 'bootstrap';
import { http } from './http.js';
import { notify } from './toast.js';
import { busy } from './busy.js';

/**
 * Admin : réassigner un article à un agent (bouton `data-reassign-url`) ou
 * lui laisser un commentaire (bouton `data-note-url`), depuis le tableau des
 * articles ou depuis la vue « Voir » d'un agent dans les statistiques.
 *
 * Le serveur fait foi : dans le tableau, la ligne est remplacée par celle
 * qu'il renvoie ; ailleurs, la page est rechargée.
 */
export function initReassign() {
    const element = document.getElementById('ag-reassign-modal');

    if (!element) {
        return;
    }

    const modal = new Modal(element);
    const form = element.querySelector('[data-reassign-form]');
    const heading = element.querySelector('[data-reassign-heading]');
    const articleLabel = element.querySelector('[data-reassign-article]');
    const agentGroup = element.querySelector('[data-reassign-agent-group]');
    const agentSelect = form.querySelector('[name="agent"]');
    const comment = form.querySelector('[name="comment"]');
    const optional = element.querySelector('[data-reassign-optional]');
    const submit = element.querySelector('[data-reassign-submit]');

    let current = null;

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-reassign-url], [data-note-url]');

        if (!button) return;

        event.preventDefault();

        const noteOnly = button.hasAttribute('data-note-url');
        current = {
            button,
            noteOnly,
            url: noteOnly ? button.dataset.noteUrl : button.dataset.reassignUrl,
        };

        heading.textContent = noteOnly ? 'Commenter l’article' : 'Réassigner l’article';
        submit.textContent = noteOnly ? 'Envoyer le commentaire' : 'Réassigner';
        articleLabel.textContent = button.dataset.reassignTitle ?? '';
        agentGroup.hidden = noteOnly;
        optional.hidden = noteOnly;
        comment.value = '';

        if (!noteOnly) {
            filterAgents(button.dataset.reassignSite);
            agentSelect.value = button.dataset.reassignAgent ?? '';

            // Agent précédent absent de la liste (plus assigné au site) :
            // l'Admin choisit.
            if (agentSelect.selectedOptions[0]?.hidden) {
                agentSelect.value = '';
            }
        }

        modal.show();
    });

    element.addEventListener('shown.bs.modal', () => {
        (current?.noteOnly ? comment : agentSelect).focus();
    });

    /** Seuls les agents assignés au site de l'article sont proposés. */
    function filterAgents(siteId) {
        agentSelect.querySelectorAll('option[data-sites]').forEach((option) => {
            const sites = (option.dataset.sites || '').split(',').filter(Boolean);
            const allowed = !siteId || sites.includes(String(siteId));

            option.hidden = !allowed;
            option.disabled = !allowed;
        });
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!current) return;

        if (!current.noteOnly && !agentSelect.value) {
            notify.error('Choisissez l’agent à qui réassigner l’article.');
            agentSelect.focus();
            return;
        }

        if (current.noteOnly && comment.value.trim() === '') {
            notify.error('Écrivez le commentaire à transmettre à l’agent.');
            comment.focus();
            return;
        }

        const done = busy(submit, current.noteOnly ? 'Envoi…' : 'Réassignation…');

        const payload = { comment: comment.value.trim() || null };
        if (!current.noteOnly) payload.agent = Number(agentSelect.value);

        try {
            const data = await http.post(current.url, payload);

            modal.hide();
            notify.success(data.message);
            applyResult(current.button, data);
        } catch (error) {
            notify.error(error.message);
        } finally {
            done();
        }
    });
}

function applyResult(button, data) {
    const row = button.closest('#ag-articles-body tr[data-article-id]');

    if (row && data.row) {
        Tooltip.getInstance(button)?.dispose();
        const id = row.dataset.articleId;
        row.outerHTML = data.row;

        const replaced = document.querySelector(`#ag-articles-body tr[data-article-id="${id}"]`);
        replaced?.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
            Tooltip.getOrCreateInstance(element);
        });
        replaced?.classList.add('ag-row-flash');
        return;
    }

    // Vue « Voir » d'un agent : la ligne change de section (Corrigé → En
    // cours), le plus simple est de recharger.
    setTimeout(() => window.location.reload(), 600);
}
