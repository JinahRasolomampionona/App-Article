{{-- Historique des scans : premier et dernier scan de chaque article. --}}
@php
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value) : null;
    $first = $date($totals['first']);
    $last = $date($totals['last']);
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="ag-stat">
            <p class="ag-stat__label"><i class="bi bi-activity" aria-hidden="true"></i> Scans effectués</p>
            <p class="ag-stat__value">{{ number_format($totals['scans'], 0, ',', ' ') }}</p>
            <p class="ag-stat__hint">tous articles du site</p>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-stat">
            <p class="ag-stat__label"><i class="bi bi-hourglass-top" aria-hidden="true"></i> Scan le plus ancien</p>
            <p class="ag-stat__value" style="font-size:1.1rem;">{{ $first?->translatedFormat('d/m/Y') ?? '—' }}</p>
            <p class="ag-stat__hint">{{ $first?->diffForHumans() ?? 'aucun scan' }}</p>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ag-stat">
            <p class="ag-stat__label"><i class="bi bi-hourglass-bottom" aria-hidden="true"></i> Scan le plus récent</p>
            <p class="ag-stat__value" style="font-size:1.1rem;">{{ $last?->translatedFormat('d/m/Y H:i') ?? '—' }}</p>
            <p class="ag-stat__hint">{{ $last?->diffForHumans() ?? 'aucun scan' }}</p>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <a href="{{ route('audits.index', ['tab' => 'history', 'period' => $totals['never'] > 0 ? 'never' : 'older']) }}"
           class="ag-stat ag-stat--link {{ $totals['never'] + $totals['stale'] > 0 ? 'ag-stat--danger' : '' }}">
            <p class="ag-stat__label">
                <i class="bi bi-exclamation-circle" aria-hidden="true"></i> À rescanner
                <i class="bi bi-arrow-right-short ms-auto ag-stat__go" aria-hidden="true"></i>
            </p>
            <p class="ag-stat__value">{{ $totals['never'] + $totals['stale'] }}</p>
            <p class="ag-stat__hint">{{ $totals['never'] }} jamais scanné(s) · {{ $totals['stale'] }} &gt; 30 jours</p>
        </a>
    </div>
</div>

<form method="GET" action="{{ route('audits.index') }}" class="ag-card mb-3">
    <input type="hidden" name="tab" value="history">
    <div class="ag-card__body">
        <div class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label for="ag-scan-search" class="form-label small mb-1">Rechercher</label>
                <input type="search" id="ag-scan-search" name="search" value="{{ $filters['search'] }}"
                       class="form-control form-control-sm" placeholder="Titre, slug, URL ou ID WordPress">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="ag-scan-period" class="form-label small mb-1">Dernier scan</label>
                <select id="ag-scan-period" name="period" class="form-select form-select-sm">
                    <option value="">Toutes les dates</option>
                    <option value="today" @selected($filters['period'] === 'today')>Aujourd’hui</option>
                    <option value="week" @selected($filters['period'] === 'week')>7 derniers jours</option>
                    <option value="month" @selected($filters['period'] === 'month')>30 derniers jours</option>
                    <option value="older" @selected($filters['period'] === 'older')>Il y a plus de 30 jours</option>
                    <option value="never" @selected($filters['period'] === 'never')>Jamais scanné</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="ag-scan-sort" class="form-label small mb-1">Trier par</label>
                <select id="ag-scan-sort" name="sort" class="form-select form-select-sm">
                    <option value="recent" @selected($filters['sort'] === 'recent')>Scan le plus récent d’abord</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>Scan le plus ancien d’abord</option>
                </select>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">Filtrer</button>
                @if($filters['search'] !== '' || $filters['period'] !== '' || $filters['sort'] !== 'recent')
                    <a href="{{ route('audits.index', ['tab' => 'history']) }}" class="btn btn-sm btn-outline-secondary"
                       aria-label="Réinitialiser les filtres" title="Réinitialiser">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                @endif
            </div>
        </div>
    </div>
</form>

<div class="ag-card">
    <div class="ag-card__header">
        <h2 class="ag-card__title">Historique des scans par article</h2>
        <span class="ag-hint ms-auto">{{ $articles->total() }} article(s)</span>
    </div>

    <div class="table-responsive">
        <table class="table ag-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Article</th>
                    <th scope="col">Premier scan</th>
                    <th scope="col">Dernier scan</th>
                    <th scope="col" class="text-end">Scans</th>
                    <th scope="col">Statut actuel</th>
                    <th scope="col" class="text-end">Historique</th>
                </tr>
            </thead>
            <tbody>
            @forelse($articles as $article)
                @php
                    $firstScan = $date($article->scans_min_created_at);
                    $lastScan = $date($article->scans_max_created_at);
                    $isStale = $lastScan && $lastScan->lt(now()->subDays(30));
                @endphp
                <tr>
                    <td style="max-width: 22rem;">
                        <a href="{{ route('articles.edit', $article) }}" class="ag-table__title text-truncate"
                           title="{{ $article->title }}">{{ $article->title }}</a>
                        <span class="ag-table__url">#{{ $article->wp_id }} · {{ $article->relativePath() }}</span>
                    </td>
                    <td>
                        @if($firstScan)
                            <span>{{ $firstScan->translatedFormat('d/m/Y H:i') }}</span>
                            <span class="ag-hint d-block">{{ $firstScan->diffForHumans() }}</span>
                        @else
                            <span class="ag-hint">—</span>
                        @endif
                    </td>
                    <td>
                        @if($lastScan)
                            <span @class(['fw-semibold', 'text-danger' => $isStale])>{{ $lastScan->translatedFormat('d/m/Y H:i') }}</span>
                            <span class="ag-hint d-block">
                                {{ $lastScan->diffForHumans() }}@if($isStale) · à rescanner @endif
                            </span>
                        @else
                            <span class="ag-badge ag-badge--warning">Jamais scanné</span>
                        @endif
                    </td>
                    <td class="text-end">{{ $article->scans_count }}</td>
                    <td>
                        @if($article->statusLabel())
                            <span class="ag-badge ag-badge--{{ $article->statusVariant() }}">{{ $article->statusLabel() }}</span>
                        @else
                            <span class="ag-badge ag-badge--muted">En attente d’analyse</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-scans-url="{{ route('articles.scans', $article) }}"
                                @disabled($article->scans_count === 0)
                                aria-label="Voir les scans de {{ $article->title }}">
                            <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Voir
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <p class="ag-hint mb-0 py-4 text-center">Aucun article ne correspond à ces filtres.</p>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($articles->hasPages())
        <div class="ag-card__body border-top">
            {{ $articles->links() }}
        </div>
    @endif
</div>

{{-- Modal : tous les scans d'un article --}}
<div class="modal fade" id="ag-scans-modal" tabindex="-1" aria-labelledby="ag-scans-heading" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="min-w-0">
                    <h2 class="modal-title h6 mb-0" id="ag-scans-heading">
                        Historique des scans <span class="ag-hint" data-scans-count></span>
                    </h2>
                    <p class="ag-hint mb-0 text-truncate" data-scans-title></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" data-scans-list></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>
