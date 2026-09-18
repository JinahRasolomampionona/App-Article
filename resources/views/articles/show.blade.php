@extends('layouts.app')

@section('title', 'Détail de l’article')
@section('heading', $article->title)
@section('subheading', $article->site->name.' · #'.$article->wp_id)

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <a href="{{ route('articles.index') }}">Articles</a> <span class="mx-1">/</span>
    <span class="active">{{ Str::limit($article->title, 48) }}</span>
@endsection

@section('actions')
    @if($article->link)
        <a href="{{ $article->link }}" target="_blank" rel="noopener noreferrer"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i> Voir en ligne
        </a>
    @endif
    <a href="{{ route('articles.edit', $article) }}" class="btn btn-sm btn-primary">Éditer</a>
@endsection

@section('content')
<div class="row g-3">
    <div class="col-lg-8">
        <div class="ag-card">
            <div class="ag-card__header">
                <h2 class="ag-card__title">Aperçu du contenu</h2>
                <span class="ag-hint ms-auto">Rendu assaini, à titre indicatif</span>
            </div>
            <div class="ag-card__body">
                @if($article->featured_media_url)
                    <img src="{{ $article->featured_media_url }}" alt="{{ $article->featured_media_alt }}"
                         class="ag-thumb mb-3">
                @endif

                <div class="ag-editor__surface" style="min-height:auto;max-height:none;padding:0;">
                    {{-- Contenu d'un site tiers : assaini avant affichage. --}}
                    {!! \App\Support\HtmlContent::make($article->content)->sanitized() !!}
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="ag-card mb-3">
            <div class="ag-card__header">
                <h2 class="ag-card__title">Problèmes ouverts</h2>
            </div>
            <div class="ag-card__body">
                @forelse($openIssues as $issue)
                    <div class="ag-audit-item ag-audit-item--{{ $issue->severityVariant() === 'danger' ? 'error' : $issue->severityVariant() }}">
                        <span class="ag-audit-item__icon" aria-hidden="true"><i class="bi bi-exclamation-lg"></i></span>
                        <span>
                            <span class="d-block fw-medium">{{ $issue->message }}</span>
                            <span class="ag-audit-item__meta">
                                Détecté {{ $issue->detected_at?->diffForHumans() ?? '—' }}
                            </span>
                        </span>
                    </div>
                @empty
                    <p class="ag-hint mb-0">Aucun problème ouvert sur cet article.</p>
                @endforelse
            </div>
        </div>

        @if($resolvedIssues->isNotEmpty())
            <div class="ag-card mb-3">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Problèmes résolus</h2>
                </div>
                <div class="ag-card__body">
                    @foreach($resolvedIssues->take(10) as $issue)
                        <div class="d-flex justify-content-between gap-2 small py-1">
                            <span class="text-decoration-line-through ag-muted">{{ $issue->message }}</span>
                            <span class="ag-hint text-nowrap">{{ $issue->resolved_at?->diffForHumans() }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="ag-card">
            <div class="ag-card__header">
                <h2 class="ag-card__title">Informations</h2>
            </div>
            <div class="ag-card__body">
                <dl class="row small mb-0">
                    <dt class="col-5 fw-normal ag-muted">Statut</dt>
                    <dd class="col-7">{{ $article->statusLabel() ?? 'Non audité' }}</dd>
                    <dt class="col-5 fw-normal ag-muted">Catégories</dt>
                    <dd class="col-7">{{ $article->categories->pluck('name')->join(', ') ?: '—' }}</dd>
                    <dt class="col-5 fw-normal ag-muted">URL</dt>
                    <dd class="col-7 ag-mono text-break">{{ $article->relativePath() }}</dd>
                    <dt class="col-5 fw-normal ag-muted">Publié le</dt>
                    <dd class="col-7">{{ $article->wordpress_published_at?->translatedFormat('d M Y') ?? '—' }}</dd>
                    <dt class="col-5 fw-normal ag-muted">Dernière analyse</dt>
                    <dd class="col-7">{{ $article->last_audited_at?->diffForHumans() ?? 'Jamais' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
