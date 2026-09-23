/**
 * Sections repliables de la sidebar.
 *
 * L'état est conservé d'une page à l'autre : replier « Configuration » n'a
 * d'intérêt que si le choix tient. La sidebar étant rendue deux fois (fixe et
 * offcanvas), les deux copies d'une même section sont synchronisées.
 */

import { Tooltip } from 'bootstrap';

const STORAGE_KEY = 'ag.nav.collapsed';
const RAIL_KEY = 'ag.nav.rail';
const RAIL_CLASS = 'ag-nav-collapsed';

export function initNavGroups() {
    const groups = document.querySelectorAll('[data-nav-group]');

    if (groups.length === 0) {
        return;
    }

    const collapsed = new Set(read());

    groups.forEach((group) => {
        apply(group, collapsed.has(group.dataset.navGroup));

        group.querySelector('[data-nav-toggle]')?.addEventListener('click', () => {
            const key = group.dataset.navGroup;
            const next = !group.classList.contains('is-collapsed');

            if (next) {
                collapsed.add(key);
            } else {
                collapsed.delete(key);
            }

            document
                .querySelectorAll(`[data-nav-group="${CSS.escape(key)}"]`)
                .forEach((twin) => apply(twin, next));

            write([...collapsed]);
        });
    });
}

/**
 * Réduction de la sidebar en barre d'icônes.
 *
 * La classe est déjà posée sur `<html>` par le script d'amorçage du layout ;
 * ce module ne fait que la basculer, l'annoncer aux technologies d'assistance
 * et remplacer les libellés masqués par des infobulles.
 */
export function initSidebarRail() {
    const toggle = document.querySelector('[data-nav-rail]');

    if (!toggle) {
        return;
    }

    const label = toggle.querySelector('[data-nav-rail-label]');

    // Les infobulles ne sont utiles qu'une fois les libellés masqués. Placées
    // dans `body`, elles échappent au défilement de la sidebar, qui les
    // rognerait.
    const tooltips = Array.from(document.querySelectorAll('#ag-sidebar .ag-nav__link')).map(
        (link) =>
            new Tooltip(link, {
                title: link.dataset.label ?? '',
                placement: 'right',
                trigger: 'hover focus',
                container: 'body',
            }),
    );

    function apply(isRail) {
        document.documentElement.classList.toggle(RAIL_CLASS, isRail);
        toggle.setAttribute('aria-expanded', String(!isRail));
        toggle.title = isRail ? 'Déployer le menu' : 'Réduire le menu';

        if (label) {
            label.textContent = toggle.title;
        }

        tooltips.forEach((tooltip) => {
            tooltip.hide();
            if (isRail) {
                tooltip.enable();
            } else {
                tooltip.disable();
            }
        });
    }

    apply(document.documentElement.classList.contains(RAIL_CLASS));

    toggle.addEventListener('click', () => {
        const next = !document.documentElement.classList.contains(RAIL_CLASS);

        apply(next);

        try {
            window.localStorage.setItem(RAIL_KEY, next ? '1' : '0');
        } catch {
            // Sans persistance, le choix vaut pour la page en cours.
        }
    });
}

function apply(group, isCollapsed) {
    group.classList.toggle('is-collapsed', isCollapsed);
    group
        .querySelector('[data-nav-toggle]')
        ?.setAttribute('aria-expanded', String(!isCollapsed));
}

function read() {
    try {
        const stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '[]');

        return Array.isArray(stored) ? stored.filter((key) => typeof key === 'string') : [];
    } catch {
        // Stockage indisponible (navigation privée, quota) : toutes les
        // sections restent ouvertes, ce qui est l'état par défaut.
        return [];
    }
}

function write(keys) {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(keys));
    } catch {
        // Sans persistance, le repli reste valable pour la page en cours.
    }
}
