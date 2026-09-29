{{-- Quatre cartes cliquables : chacune ouvre « Articles » filtré sur le même
     statut ; les autres filtres (agent, catégories…) restent disponibles.

     @param array $cards     résultat de ArticleStatisticsService::statusCards()
     @param int|null $linkSite  site à ouvrir (null : site courant)
     @param string|null $scope  précision affichée sous le chiffre --}}
@php
    $params = fn (?string $status) => array_filter([
        'site' => $linkSite ?? null,
        'status' => $status,
    ]);

    $items = [
        [
            'status' => null,
            'label' => 'Total articles',
            'icon' => 'bi-files',
            'value' => $cards['total'],
            'variant' => '',
            'hint' => ($scope ?? null) ?: ($cards['pending'] > 0 ? $cards['pending'].' en attente d’analyse' : 'tous statuts'),
        ],
        [
            'status' => \App\Models\WordpressArticle::AUDIT_NEEDS_FIX,
            'label' => 'À corriger',
            'icon' => 'bi-exclamation-triangle',
            'value' => $cards['needs_fix'],
            'variant' => 'ag-stat--danger',
            'hint' => 'problèmes détectés par l’audit',
        ],
        [
            'status' => \App\Models\WordpressArticle::STATUS_TO_REVIEW,
            'label' => 'À vérifier',
            'icon' => 'bi-search',
            'value' => $cards['to_review'],
            'variant' => 'ag-stat--info',
            'hint' => 'aucun problème détecté',
        ],
        [
            'status' => \App\Models\WordpressArticle::STATUS_DONE,
            'label' => 'Corrigés',
            'icon' => 'bi-check2-circle',
            'value' => $cards['fixed'],
            'variant' => 'ag-stat--success',
            'hint' => 'déclarés corrigés par un agent',
        ],
    ];
@endphp

<div class="row g-3 mb-3">
    @foreach($items as $item)
        <div class="col-6 col-lg-3">
            <a href="{{ route('articles.index', $params($item['status'])) }}"
               class="ag-stat ag-stat--link {{ $item['variant'] }}"
               aria-label="{{ $item['label'] }} : {{ $item['value'] }} article(s). Ouvrir la liste.">
                <p class="ag-stat__label">
                    <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i> {{ $item['label'] }}
                    <i class="bi bi-arrow-right-short ms-auto ag-stat__go" aria-hidden="true"></i>
                </p>
                <p class="ag-stat__value">{{ number_format($item['value'], 0, ',', ' ') }}</p>
                <p class="ag-stat__hint mb-0">{{ $item['hint'] }}</p>
            </a>
        </div>
    @endforeach
</div>
