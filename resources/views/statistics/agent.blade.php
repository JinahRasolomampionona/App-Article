@extends('layouts.app')

@section('title', 'Articles de '.$agent->name)
@section('heading', 'Articles de '.$agent->name)
@section('subheading', 'Articles en cours et corrigés par '.$agent->name.' : vérifiez, commentez ou réassignez.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <a href="{{ route('statistics.index', array_filter(['site' => $siteFilter])) }}">Statistiques</a> <span class="mx-1">/</span>
    <span class="active">{{ $agent->name }}</span>
@endsection

@section('actions')
    {{-- Retour aux statistiques, en gardant le site filtré. --}}
    <a href="{{ route('statistics.index', array_filter(['site' => $siteFilter])) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Retour aux statistiques
    </a>
@endsection

@section('content')
    @include('statistics._filters', ['filterAction' => route('statistics.agent', $agent)])

    <div class="ag-card mb-3">
        <div class="ag-card__header">
            <h2 class="ag-card__title">Articles traités par {{ $agent->name }}</h2>
            <span class="ag-hint ms-auto">
                {{ $articles->total() }} article(s)
                · @if($siteFilter) site sélectionné @else tous sites confondus @endif
            </span>
        </div>

        <div class="table-responsive">
            <table class="table ag-table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Site</th>
                        <th scope="col">Article</th>
                        <th scope="col">Statut</th>
                        <th scope="col">Erreurs corrigées</th>
                        <th scope="col">Commentaires</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($articles as $article)
                    @php
                        $inProgress = ! $article->isCompleted();
                        $completion = $completions->get($article->id);
                        $corrected = $completion?->resolved_issues ?? [];
                    @endphp
                    <tr>
                        <td>
                            <span class="ag-table__title text-truncate">{{ $article->site?->name ?? '—' }}</span>
                            <span class="ag-table__url">{{ $article->site?->url }}</span>
                        </td>

                        <td style="max-width: 18rem;">
                            <a href="{{ route('articles.edit', $article) }}" class="ag-table__title text-truncate"
                               title="{{ $article->title }}">{{ $article->title }}</a>
                            <span class="ag-table__url">{{ $article->relativePath() }}</span>
                        </td>

                        <td>
                            @if($inProgress)
                                <span class="ag-lock ag-lock--other">
                                    <span class="ag-lock__dot" aria-hidden="true"></span> En cours
                                </span>
                                <span class="ag-hint d-block mt-1">depuis {{ $article->locked_at?->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                            @else
                                <span class="ag-badge ag-badge--success">
                                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Corrigé
                                </span>
                                <span class="ag-hint d-block mt-1">le {{ $article->completed_at->translatedFormat('d/m/Y H:i') }}</span>
                            @endif
                        </td>

                        {{-- Erreurs corrigées par l'agent (relevées au moment où il
                             a déclaré l'article corrigé) ; pour un article en
                             cours, celles qui restent ouvertes. --}}
                        <td style="min-width: 14rem;">
                            @if($inProgress)
                                @if($article->openIssues->isEmpty())
                                    <span class="ag-hint">Aucun problème ouvert (à vérifier)</span>
                                @else
                                    <span class="ag-hint d-block mb-1">Reste à corriger :</span>
                                    <div class="d-flex flex-wrap gap-1">
                                        @foreach($article->openIssues as $issue)
                                            <span class="ag-badge ag-badge--{{ $issue->severityVariant() }}"
                                                  data-bs-toggle="tooltip" title="{{ $issue->message }}">
                                                {{ \App\Support\IssueCatalog::short($issue->rule_type) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            @elseif($corrected === [])
                                <span class="ag-hint">Vérifié — aucune erreur détectée</span>
                            @else
                                <ul class="list-unstyled mb-0 small">
                                    @foreach($corrected as $issue)
                                        <li class="d-flex gap-1 align-items-start">
                                            <i class="bi bi-check2 text-success" aria-hidden="true"></i>
                                            <span>{{ $issue['message'] ?? \App\Support\IssueCatalog::label($issue['type'] ?? '') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- Problèmes réapparus depuis (audit relancé après la correction). --}}
                            @if(! $inProgress && $article->openIssues->isNotEmpty())
                                <span class="ag-badge ag-badge--danger mt-1" data-bs-toggle="tooltip"
                                      title="{{ $article->openIssues->pluck('message')->implode(' · ') }}">
                                    {{ $article->openIssues->count() }} problème(s) détecté(s) depuis
                                </span>
                            @endif
                        </td>

                        <td style="min-width: 12rem;">
                            @forelse($article->notes->take(3) as $note)
                                <div class="ag-note small">
                                    <span style="white-space: pre-line;">{{ \Illuminate\Support\Str::limit($note->body, 160) }}</span>
                                    <span class="ag-hint d-block">{{ $note->author?->name ?? 'Admin' }} · {{ $note->created_at?->translatedFormat('d/m/Y H:i') }}</span>
                                </div>
                            @empty
                                <span class="ag-hint">—</span>
                            @endforelse
                            @if($article->notes->count() > 3)
                                <span class="ag-hint">+{{ $article->notes->count() - 3 }} plus ancien(s)</span>
                            @endif
                        </td>

                        <td class="text-end">
                            <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-note-url="{{ route('articles.notes', $article) }}"
                                        data-reassign-title="{{ $article->title }}"
                                        aria-label="Commenter l’article {{ $article->title }}">
                                    <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>Commenter
                                </button>
                                @unless($inProgress)
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-reassign-url="{{ route('articles.reassign', $article) }}"
                                            data-reassign-agent="{{ $agent->id }}"
                                            data-reassign-site="{{ $article->wordpress_site_id }}"
                                            data-reassign-title="{{ $article->title }}"
                                            aria-label="Réassigner l’article {{ $article->title }}">
                                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Réassigner
                                    </button>
                                @endunless
                                <a href="{{ route('articles.edit', $article) }}" class="btn btn-sm btn-outline-secondary"
                                   aria-label="Ouvrir l’article {{ $article->title }}">
                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <p class="ag-hint mb-0 py-4 text-center">
                                {{ $agent->name }} n’a aucun article en cours ni corrigé
                                @if($siteFilter) sur ce site @endif.
                            </p>
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

    @include('articles.partials.reassign-modal', ['agents' => $agents])
@endsection
