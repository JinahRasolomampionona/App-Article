@extends('layouts.app')

@section('title', 'Audits')
@section('heading', 'Audits')
@section('subheading', $site ? 'Problèmes ouverts sur '.$site->name : 'Sélectionnez un site WordPress.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <span class="active">Audits</span>
@endsection

@section('actions')
    @if($site)
        <button type="button" class="btn btn-sm btn-primary" id="ag-run-site-audit"
                data-url="{{ route('audits.run') }}">
            <i class="bi bi-clipboard-check me-1" aria-hidden="true"></i> Auditer tout le site
        </button>
    @endif
@endsection

@section('content')
@if(! $site)
    <div class="ag-card">
        <div class="ag-empty">
            <div class="ag-empty__icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div>
            <p class="ag-empty__title">Aucun site sélectionné</p>
            <p class="ag-empty__text">Connectez un site WordPress pour lancer des audits de contenu.</p>
            <a href="{{ route('sites.create') }}" class="btn btn-primary btn-sm mt-3">Connecter un site</a>
        </div>
    </div>
@else

    {{-- Répartition par règle --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('audits.index') }}"
           class="ag-badge {{ $ruleFilter ? 'ag-badge--muted' : 'ag-badge--primary' }} text-decoration-none">
            Tous
        </a>
        @foreach($summary as $row)
            <a href="{{ route('audits.index', ['rule' => $row['type']]) }}"
               class="ag-badge {{ $ruleFilter === $row['type'] ? 'ag-badge--primary' : 'ag-badge--muted' }} text-decoration-none">
                {{ $row['label'] }} <strong>{{ $row['total'] }}</strong>
            </a>
        @endforeach
    </div>

    <div class="ag-card">
        <div class="ag-card__header">
            <h2 class="ag-card__title">Problèmes ouverts</h2>
            <span class="ag-hint ms-auto">{{ $issues->total() }} problème(s)</span>
        </div>

        <div class="table-responsive">
            <table class="table ag-table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Article</th>
                        <th scope="col">Problème</th>
                        <th scope="col">Sévérité</th>
                        <th scope="col">Détecté</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($issues as $issue)
                    <tr>
                        <td>
                            <a href="{{ route('articles.edit', $issue->article) }}"
                               class="ag-table__title text-truncate">{{ $issue->article?->title }}</a>
                            <span class="ag-table__url">{{ $issue->article?->relativePath() }}</span>
                        </td>
                        <td>{{ $issue->message }}</td>
                        <td>
                            <span class="ag-badge ag-badge--{{ $issue->severityVariant() }}">
                                {{ ['error' => 'Bloquant', 'warning' => 'À corriger', 'info' => 'Info'][$issue->severity] ?? $issue->severity }}
                            </span>
                        </td>
                        <td><span class="ag-hint">{{ $issue->detected_at?->diffForHumans() ?? '—' }}</span></td>
                        <td class="text-end">
                            <a href="{{ route('articles.edit', $issue->article) }}"
                               class="btn btn-sm btn-outline-primary">Corriger</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="ag-empty py-5">
                                <div class="ag-empty__icon"><i class="bi bi-check-circle" aria-hidden="true"></i></div>
                                <p class="ag-empty__title">Aucun problème ouvert</p>
                                <p class="ag-empty__text">
                                    Tous les articles audités sont conformes aux règles activées.
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if($issues->hasPages())
            <div class="ag-card__body border-top">
                {{ $issues->links() }}
            </div>
        @endif
    </div>
@endif
@endsection
