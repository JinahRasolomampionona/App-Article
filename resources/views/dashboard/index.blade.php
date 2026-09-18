@extends('layouts.app')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('subheading', $site ? 'Vue d’ensemble de '.$site->name : 'Vue d’ensemble de vos contenus WordPress')

@section('breadcrumb')
    <span class="active">Dashboard</span>
@endsection

@section('actions')
    @if($site)
        <a href="{{ route('articles.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-file-text me-1" aria-hidden="true"></i> Voir les articles
        </a>
        <a href="{{ route('sites.index') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Synchroniser
        </a>
    @endif
@endsection

@section('content')
    @if(! $site)
        <div class="ag-card">
            <div class="ag-empty">
                <div class="ag-empty__icon"><i class="bi bi-globe2" aria-hidden="true"></i></div>
                <p class="ag-empty__title">Aucun site WordPress connecté</p>
                <p class="ag-empty__text">
                    Connectez un site pour récupérer ses catégories et ses articles, puis lancer
                    un premier audit de contenu.
                </p>
                <a href="{{ route('sites.create') }}" class="btn btn-primary btn-sm mt-3">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Connecter un site WordPress
                </a>
            </div>
        </div>
    @else
        {{-- Cartes statistiques --}}
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3">
                <div class="ag-stat">
                    <p class="ag-stat__label"><i class="bi bi-file-text" aria-hidden="true"></i> Articles</p>
                    <p class="ag-stat__value">{{ number_format($stats['articles'], 0, ',', ' ') }}</p>
                    <p class="ag-stat__hint">{{ $stats['categories'] }} catégorie(s)</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="ag-stat ag-stat--success">
                    <p class="ag-stat__label"><i class="bi bi-check-circle" aria-hidden="true"></i> Sans erreur</p>
                    <p class="ag-stat__value">{{ $stats['ok'] }}</p>
                    <p class="ag-stat__hint">audit sans remarque</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="ag-stat ag-stat--danger">
                    <p class="ag-stat__label"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> À corriger</p>
                    <p class="ag-stat__value">{{ $stats['needs_fix'] }}</p>
                    <p class="ag-stat__hint">{{ $stats['issues'] }} problème(s) ouvert(s)</p>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="ag-stat">
                    <p class="ag-stat__label"><i class="bi bi-clock-history" aria-hidden="true"></i> Dernière synchro</p>
                    <p class="ag-stat__value" style="font-size:1.05rem;">
                        {{ $stats['last_sync_at']?->diffForHumans() ?? 'Jamais' }}
                    </p>
                    <p class="ag-stat__hint">
                        {{ $stats['pending'] }} article(s) en attente d’audit
                    </p>
                </div>
            </div>
        </div>

        <div class="row g-3">
            {{-- Répartition OK / à corriger --}}
            <div class="col-lg-5">
                <div class="ag-card h-100">
                    <div class="ag-card__header">
                        <h2 class="ag-card__title">État des articles</h2>
                    </div>
                    <div class="ag-card__body">
                        @php
                            $donutSegments = [
                                ['label' => 'Sans erreur', 'value' => $stats['ok'], 'color' => '#2e9b68'],
                                ['label' => 'À corriger', 'value' => $stats['needs_fix'], 'color' => '#d9534f'],
                                ['label' => 'En attente', 'value' => $stats['pending'], 'color' => '#c9cdd9'],
                            ];
                        @endphp

                        <div data-donut="@json($donutSegments)"
                             data-donut-label="{{ $stats['articles'] }}"
                             data-donut-caption="articles"></div>
                    </div>
                </div>
            </div>

            {{-- Répartition des problèmes --}}
            <div class="col-lg-7">
                <div class="ag-card h-100">
                    <div class="ag-card__header">
                        <h2 class="ag-card__title">Répartition des problèmes</h2>
                        <a href="{{ route('audits.index') }}" class="ms-auto small">Tout voir</a>
                    </div>
                    <div class="ag-card__body">
                        @forelse($distribution as $row)
                            @php $ratio = $stats['issues'] > 0 ? round($row['total'] / $stats['issues'] * 100) : 0; @endphp
                            <div class="mb-3">
                                <div class="d-flex justify-content-between small mb-1">
                                    <a href="{{ route('audits.index', ['rule' => $row['type']]) }}"
                                       class="text-decoration-none text-body">{{ $row['label'] }}</a>
                                    <span class="ag-muted">{{ $row['total'] }}</span>
                                </div>
                                <div class="ag-bar"><span style="width: {{ max(3, $ratio) }}%"></span></div>
                            </div>
                        @empty
                            <p class="ag-muted small mb-0">
                                Aucun problème ouvert. Lancez un audit si vos articles n’ont pas encore été analysés.
                            </p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Articles prioritaires --}}
        @if($recent->isNotEmpty())
            <div class="ag-card mt-3">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Articles à corriger en priorité</h2>
                    <a href="{{ route('articles.index', ['status' => 'needs_fix']) }}" class="ms-auto small">Tout voir</a>
                </div>
                <div class="table-responsive">
                    <table class="table ag-table">
                        <thead>
                            <tr>
                                <th scope="col">Titre</th>
                                <th scope="col">Remarques</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($recent as $article)
                            <tr>
                                <td>
                                    <a href="{{ route('articles.edit', $article) }}" class="ag-table__title text-truncate">
                                        {{ $article->title }}
                                    </a>
                                    <span class="ag-table__url">{{ $article->relativePath() }}</span>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        @foreach($article->openIssues as $issue)
                                            <span class="ag-badge ag-badge--{{ $issue->severityVariant() }}">
                                                {{ \App\Support\IssueCatalog::short($issue->rule_type) }}
                                            </span>
                                        @endforeach
                                        @if($article->issues_count > $article->openIssues->count())
                                            <span class="ag-chip">+{{ $article->issues_count - $article->openIssues->count() }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('articles.edit', $article) }}" class="btn btn-sm btn-outline-secondary">
                                        Éditer
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
@endsection
