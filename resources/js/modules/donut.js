/**
 * Petit graphique en anneau, dessiné en SVG sans bibliothèque externe.
 *
 * Le dashboard n'a besoin que d'une répartition à deux ou trois parts : y
 * ajouter une dépendance de graphiques complète serait disproportionné.
 */
export function initDonuts() {
    document.querySelectorAll('[data-donut]').forEach((element) => {
        let segments;

        try {
            segments = JSON.parse(element.dataset.donut);
        } catch {
            return;
        }

        const total = segments.reduce((sum, segment) => sum + segment.value, 0);

        if (total === 0) {
            element.innerHTML = '<p class="ag-muted small mb-0">Aucune donnée à représenter.</p>';
            return;
        }

        const size = 132;
        const stroke = 16;
        const radius = (size - stroke) / 2;
        const circumference = 2 * Math.PI * radius;

        let offset = 0;

        const arcs = segments
            .filter((segment) => segment.value > 0)
            .map((segment) => {
                const length = (segment.value / total) * circumference;
                const arc = `<circle
                    cx="${size / 2}" cy="${size / 2}" r="${radius}"
                    fill="none"
                    stroke="${segment.color}"
                    stroke-width="${stroke}"
                    stroke-dasharray="${length} ${circumference - length}"
                    stroke-dashoffset="${-offset}"
                    transform="rotate(-90 ${size / 2} ${size / 2})"
                ><title>${segment.label} : ${segment.value}</title></circle>`;

                offset += length;

                return arc;
            })
            .join('');

        const label = element.dataset.donutLabel ?? String(total);

        element.innerHTML = `
            <div class="ag-donut">
                <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" role="img"
                     aria-label="${segments.map((s) => `${s.label} : ${s.value}`).join(', ')}">
                    <circle cx="${size / 2}" cy="${size / 2}" r="${radius}" fill="none"
                            stroke="#f1f2f6" stroke-width="${stroke}"></circle>
                    ${arcs}
                    <text x="50%" y="48%" text-anchor="middle" font-size="22" font-weight="650"
                          fill="#1f2430">${label}</text>
                    <text x="50%" y="62%" text-anchor="middle" font-size="10" fill="#7b8190">
                        ${element.dataset.donutCaption ?? 'articles'}
                    </text>
                </svg>
                <div class="ag-donut__legend">
                    ${segments
                        .map(
                            (segment) => `
                        <span class="d-flex align-items-center gap-2">
                            <span class="ag-donut__dot" style="background:${segment.color}"></span>
                            <span class="flex-grow-1">${segment.label}</span>
                            <strong>${segment.value}</strong>
                        </span>`,
                        )
                        .join('')}
                </div>
            </div>`;
    });
}
