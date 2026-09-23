import { http } from './http.js';
import { notify } from './toast.js';

/**
 * Articles passés à « OK » ou « Corrigé », par jour, semaine ou mois.
 *
 * Le graphique est dessiné en SVG, comme l'anneau du dashboard : une
 * bibliothèque de graphiques complète serait disproportionnée pour une série
 * d'une douzaine de barres.
 *
 * Le changement de période passe par AJAX, la page n'est pas rechargée.
 */
export function initCorrectionsChart() {
    const root = document.getElementById('ag-corrections');

    if (!root) {
        return;
    }

    const chart = root.querySelector('[data-corrections-chart]');
    const period = root.querySelector('[data-corrections-period]');
    const tabs = root.querySelectorAll('[data-corrections-tab]');

    const CAPTIONS = {
        day: '14 derniers jours',
        week: '12 dernières semaines',
        month: '12 derniers mois',
    };

    // Les séries déjà chargées sont conservées : revenir sur un onglet
    // n'entraîne pas un second aller-retour serveur.
    const cache = new Map();
    let granularity = root.dataset.granularity ?? 'day';
    let pending = null;

    try {
        cache.set(granularity, JSON.parse(root.dataset.series ?? '[]'));
    } catch {
        cache.set(granularity, []);
    }

    render(cache.get(granularity));

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => select(tab.dataset.correctionsTab));
    });

    async function select(next) {
        if (next === granularity) return;

        granularity = next;

        tabs.forEach((tab) => {
            const isActive = tab.dataset.correctionsTab === next;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', String(isActive));
        });

        if (period) {
            period.textContent = CAPTIONS[next] ?? '';
        }

        if (cache.has(next)) {
            render(cache.get(next));
            return;
        }

        // Une bascule rapide entre onglets ne doit pas afficher la réponse
        // d'un onglet qu'on a déjà quitté.
        const request = Symbol('corrections');
        pending = request;

        showSkeleton();

        try {
            // L'URL porte déjà le filtre de site : la granularité s'y ajoute.
            const url = new URL(root.dataset.url, window.location.origin);
            url.searchParams.set('granularity', next);

            const data = await http.get(url.toString());

            cache.set(next, data.series);

            if (pending === request) {
                render(data.series);
            }
        } catch (error) {
            if (pending === request) {
                chart.innerHTML = `<p class="ag-muted small mb-0">${error.message}</p>`;
                notify.error(error.message);
            }
        } finally {
            if (pending === request) {
                pending = null;
                chart.setAttribute('aria-busy', 'false');
            }
        }
    }

    function showSkeleton() {
        chart.setAttribute('aria-busy', 'true');
        chart.innerHTML = '<div class="ag-skeleton" style="height:180px;border-radius:9px"></div>';
    }

    function render(series) {
        if (!Array.isArray(series) || series.length === 0) {
            chart.innerHTML = '<p class="ag-muted small mb-0">Aucune donnée à représenter.</p>';
            return;
        }

        const max = Math.max(...series.map((bucket) => bucket.articles), 1);
        const total = series.reduce((sum, bucket) => sum + bucket.articles, 0);

        if (total === 0) {
            chart.innerHTML =
                '<p class="ag-muted small mb-0">Aucun article passé au vert sur cette période. Les corrections apparaissent ici une fois confirmées par un audit, ou déclarées depuis le tableau des articles.</p>';
            return;
        }

        const bars = series
            .map((bucket) => {
                // Une période non vide garde une barre visible, même minuscule.
                const height = bucket.articles === 0 ? 0 : Math.max(4, (bucket.articles / max) * 100);
                const title = `${bucket.full_label} : ${bucket.articles} article(s) au vert, dont ${bucket.fixed} corrigé(s)`;

                return `
                <div class="ag-bars__item">
                    <div class="ag-bars__track" title="${escapeAttribute(title)}">
                        <span class="ag-bars__count">${bucket.articles || ''}</span>
                        <span class="ag-bars__bar" style="height:${height}%"></span>
                    </div>
                    <span class="ag-bars__label">${escapeHtml(bucket.label)}</span>
                </div>`;
            })
            .join('');

        // Le tableau porte la même information pour les lecteurs d'écran : un
        // graphique en barres n'est pas lisible autrement.
        const rows = series
            .map(
                (bucket) =>
                    `<tr><th scope="row">${escapeHtml(bucket.full_label)}</th><td>${bucket.articles}</td><td>${bucket.fixed}</td></tr>`,
            )
            .join('');

        chart.innerHTML = `
            <div class="ag-bars ag-fade-in" role="presentation">${bars}</div>
            <table class="visually-hidden">
                <caption>Articles passés au vert par période</caption>
                <thead>
                    <tr><th scope="col">Période</th><th scope="col">Articles au vert</th><th scope="col">Dont corrigés</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;
    }
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';

    return div.innerHTML;
}

function escapeAttribute(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}
