import { Modal } from 'bootstrap';
import { http } from './http.js';
import { notify } from './toast.js';
import { busy, debounce } from './busy.js';

/**
 * Écran principal de gestion des articles.
 *
 * Filtres, recherche, pagination et audits fonctionnent en AJAX : la page
 * n'est jamais rechargée entièrement.
 */
export function initArticlesTable() {
    const root = document.getElementById('ag-articles');

    if (!root) {
        return;
    }

    const endpoint = root.dataset.url;
    const form = document.getElementById('ag-filters');
    const body = document.getElementById('ag-articles-body');
    const pagination = document.getElementById('ag-pagination');
    const meta = document.getElementById('ag-meta');
    const search = document.getElementById('ag-search');
    const selectAll = document.getElementById('ag-select-all');
    const bulkBar = document.getElementById('ag-bulk');
    const bulkCount = document.getElementById('ag-bulk-count');
    const bulkAudit = document.getElementById('ag-bulk-audit');
    const tableWrapper = document.getElementById('ag-table-wrapper');

    let page = 1;
    let pending = null;

    function currentParams() {
        const params = new URLSearchParams();

        form.querySelectorAll('input[name="categories[]"]:checked').forEach((input) => {
            params.append('categories[]', input.value);
        });

        const mode = form.querySelector('input[name="mode"]:checked');
        if (mode) params.set('mode', mode.value);

        const status = form.querySelector('[name="status"]');
        if (status && status.value) params.set('status', status.value);

        const agent = form.querySelector('[name="agent"]');
        if (agent && agent.value) params.set('agent', agent.value);

        const perPage = form.querySelector('[name="per_page"]');
        if (perPage && perPage.value) params.set('per_page', perPage.value);

        if (search && search.value.trim() !== '') {
            params.set('search', search.value.trim());
        }

        if (page > 1) params.set('page', String(page));

        return params;
    }

    async function load({ resetPage = true } = {}) {
        if (resetPage) page = 1;

        // Une frappe rapide dans la recherche ne doit pas empiler les requêtes.
        pending?.abort();
        pending = new AbortController();

        const params = currentParams();
        history.replaceState(null, '', `${window.location.pathname}?${params.toString()}`);

        params.set('partial', '1');
        tableWrapper?.classList.add('ag-table-loading');

        try {
            const data = await http.get(`${endpoint}?${params.toString()}`, {
                signal: pending.signal,
            });

            body.innerHTML = data.html;
            pagination.innerHTML = data.pagination;
            body.classList.add('ag-fade-in');
            setTimeout(() => body.classList.remove('ag-fade-in'), 250);

            if (meta) {
                meta.textContent = data.meta.total
                    ? `${data.meta.from}–${data.meta.to} sur ${data.meta.total} article(s)`
                    : 'Aucun article';
            }

            updateCategoryCounts(data.category_counts);
            syncSelection();
        } catch (error) {
            if (error.name === 'AbortError') return;
            notify.error(error.message);
        } finally {
            tableWrapper?.classList.remove('ag-table-loading');
        }
    }

    /**
     * Met à jour le compteur de chaque catégorie.
     *
     * Le chiffre annonce ce que donnerait la sélection compte tenu des autres
     * filtres actifs : avec « Statut : à corriger », « Bagues 10 » devient
     * « Bagues 7 ». Une catégorie qui ne ramènerait rien est estompée, sans
     * être masquée — la faire disparaître déplacerait les cases à cocher sous
     * le curseur.
     */
    function updateCategoryCounts(counts) {
        if (!counts) return;

        form?.querySelectorAll('[data-category-count]').forEach((element) => {
            const total = counts[element.dataset.categoryCount] ?? 0;

            element.textContent = String(total);
            element
                .closest('[data-category-item]')
                ?.classList.toggle('is-empty', total === 0);
        });
    }

    /* --- Filtres --- */

    form?.addEventListener('change', (event) => {
        if (event.target.name === 'site') {
            form.submit();
            return;
        }

        load();
    });

    search?.addEventListener(
        'input',
        debounce(() => load(), 350),
    );

    document.getElementById('ag-filters-reset')?.addEventListener('click', () => {
        form.querySelectorAll('input[name="categories[]"]').forEach((input) => {
            input.checked = false;
        });
        form.querySelector('[name="status"]').value = '';

        const agentFilter = form.querySelector('[name="agent"]');
        if (agentFilter) agentFilter.value = '';

        const anyMode = form.querySelector('input[name="mode"][value="any"]');
        if (anyMode) anyMode.checked = true;
        if (search) search.value = '';
        load();
    });

    /* --- Pagination --- */

    pagination?.addEventListener('click', (event) => {
        const link = event.target.closest('a.page-link');
        if (!link) return;

        event.preventDefault();

        const target = new URL(link.href, window.location.origin);
        page = Number(target.searchParams.get('page') ?? 1);
        load({ resetPage: false });
        tableWrapper?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    /* --- Sélection multiple --- */

    function selectedIds() {
        return Array.from(body.querySelectorAll('.ag-row-check:checked')).map((input) =>
            Number(input.value),
        );
    }

    function syncSelection() {
        const ids = selectedIds();
        const total = body.querySelectorAll('.ag-row-check').length;

        if (selectAll) {
            selectAll.checked = total > 0 && ids.length === total;
            selectAll.indeterminate = ids.length > 0 && ids.length < total;
        }

        if (bulkBar) {
            bulkBar.hidden = ids.length === 0;
            if (bulkCount) bulkCount.textContent = String(ids.length);
        }
    }

    selectAll?.addEventListener('change', () => {
        body.querySelectorAll('.ag-row-check').forEach((input) => {
            input.checked = selectAll.checked;
        });
        syncSelection();
    });

    body?.addEventListener('change', (event) => {
        if (event.target.classList.contains('ag-row-check')) {
            syncSelection();
        }
    });

    bulkAudit?.addEventListener('click', async () => {
        const ids = selectedIds();
        if (ids.length === 0) return;

        const done = busy(bulkAudit, 'Programmation…');

        try {
            const data = await http.post(bulkAudit.dataset.url, { ids });
            notify.success(data.message);
        } catch (error) {
            notify.error(error.message);
        } finally {
            done();
        }
    });

    /* --- Actions par ligne --- */

    body?.addEventListener('click', async (event) => {
        const issuesButton = event.target.closest('[data-issues-url]');

        if (issuesButton) {
            event.preventDefault();
            await showIssues(issuesButton.dataset.issuesUrl);
            return;
        }

        const auditButton = event.target.closest('[data-audit-url]');

        if (auditButton) {
            event.preventDefault();
            const row = auditButton.closest('tr');
            const done = busy(auditButton, '');

            try {
                const data = await http.post(auditButton.dataset.auditUrl, {});

                if (data.row && row) {
                    row.outerHTML = data.row;
                    const replaced = body.querySelector(`tr[data-article-id="${row.dataset.articleId}"]`);
                    replaced?.classList.add('ag-row-flash');
                }

                notify.success(data.message);
                syncSelection();
            } catch (error) {
                notify.error(error.message);
                done();
            }
        }
    });

    /* --- Statut posé à la main --- */

    body?.addEventListener('change', async (event) => {
        const select = event.target.closest('[data-status-url]');

        if (!select) {
            return;
        }

        const row = select.closest('tr');
        // Valeur d'avant le changement : restaurée si le serveur refuse.
        const previous = select.dataset.previous ?? select.value;

        select.disabled = true;

        try {
            const data = await http.post(select.dataset.statusUrl, { status: select.value });

            if (data.row && row) {
                row.outerHTML = data.row;
                body.querySelector(`tr[data-article-id="${row.dataset.articleId}"]`)?.classList.add('ag-row-flash');
            }

            notify.success(data.message);
            syncSelection();
        } catch (error) {
            select.value = previous;
            select.disabled = false;
            notify.error(error.message);
        }
    });

    /* --- Agent assigné --- */

    body?.addEventListener('change', async (event) => {
        const select = event.target.closest('[data-agent-url]');

        if (!select) {
            return;
        }

        const previous = select.dataset.previous ?? '';

        select.disabled = true;

        try {
            const data = await http.post(select.dataset.agentUrl, { agent: select.value || null });

            select.dataset.previous = select.value;
            notify.success(data.message);

            // La ligne peut sortir du tableau si un filtre d'agent est actif :
            // recharger évite d'afficher une ligne qui ne correspond plus.
            if (form.querySelector('[name="agent"]')?.value) {
                load({ resetPage: false });
            }
        } catch (error) {
            select.value = previous;
            notify.error(error.message);
        } finally {
            select.disabled = false;
        }
    });

    // Mémorise la valeur affichée pour pouvoir revenir en arrière en cas d'échec.
    body?.addEventListener('focusin', (event) => {
        const select = event.target.closest('[data-status-url], [data-agent-url]');

        if (select) {
            select.dataset.previous = select.value;
        }
    });

    /* --- Modal « Voir les problèmes » --- */

    const modalElement = document.getElementById('ag-issues-modal');
    const modal = modalElement ? new Modal(modalElement) : null;

    async function showIssues(url) {
        if (!modal) return;

        const list = modalElement.querySelector('[data-issues-list]');
        const title = modalElement.querySelector('[data-issues-title]');
        const editLink = modalElement.querySelector('[data-issues-edit]');

        list.innerHTML = '<div class="ag-skeleton mb-2"></div><div class="ag-skeleton w-75"></div>';
        modal.show();

        try {
            const data = await http.get(url);
            title.textContent = data.title;
            editLink.href = data.edit_url;

            if (data.issues.length === 0) {
                list.innerHTML = '<p class="ag-muted mb-0">Aucun problème ouvert sur cet article.</p>';
                return;
            }

            const items = data.issues
                .map(
                    (issue) => `
                    <li class="d-flex gap-2 align-items-start mb-2">
                        <span class="ag-badge ag-badge--${severityVariant(issue.severity)}">${escapeHtml(
                            severityLabel(issue.severity),
                        )}</span>
                        <span>${escapeHtml(issue.message)}</span>
                    </li>`,
                )
                .join('');

            list.innerHTML = `<ul class="list-unstyled mb-0">${items}</ul>`;
        } catch (error) {
            list.innerHTML = `<p class="text-danger mb-0">${escapeHtml(error.message)}</p>`;
        }
    }

    syncSelection();
}

function severityVariant(severity) {
    return { error: 'danger', warning: 'warning', info: 'info' }[severity] ?? 'neutral';
}

function severityLabel(severity) {
    return { error: 'Bloquant', warning: 'À corriger', info: 'Info' }[severity] ?? 'Info';
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}
