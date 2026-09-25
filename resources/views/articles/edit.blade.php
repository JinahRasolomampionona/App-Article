@extends('layouts.app')

@section('title', 'Éditer un article')
@section('heading', 'Éditer l’article')
@section('subheading', $site->name.' · #'.$article->wp_id)

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
    <a href="{{ route('articles.index') }}" class="btn btn-sm btn-outline-secondary">Retour</a>
@endsection

@section('content')
@unless($site->hasCredentials())
    <div class="alert alert-warning py-2 px-3 small" role="alert">
        Ce site est connecté en lecture seule. Ajoutez un identifiant WordPress et une
        Application Password dans @can('update', $site) <a href="{{ route('sites.edit', $site) }}">les paramètres du site</a> @else les paramètres du site (réservé à la personne qui l’a connecté ou à l’Admin) @endcan
        pour pouvoir enregistrer vos modifications.
    </div>
@elseif($site->isReadOnlyAccount())
    {{-- Authentifié mais sans droit d'écriture : WordPress refusera la mise à
         jour. Le dire avant la saisie évite de perdre le travail effectué. --}}
    <div class="alert alert-warning py-2 px-3 small" role="alert">
        Le compte WordPress « {{ $site->wp_username }} »
        @if($site->wp_role) (rôle « {{ $site->wp_role }} ») @endif
        n’a pas le droit de modifier les articles de ce site : WordPress refusera l’enregistrement.
        Utilisez un compte ayant au minimum le rôle « Auteur » dans
        @can('update', $site) <a href="{{ route('sites.edit', $site) }}">les paramètres du site</a> @else les paramètres du site (réservé à la personne qui l’a connecté ou à l’Admin) @endcan.
    </div>
@endunless

<form id="ag-article-form" data-url="{{ route('articles.update', $article) }}"
      data-readonly="{{ $canEdit ? '0' : '1' }}" novalidate>
    @csrf
    @method('PUT')

    <div class="row g-3">
        {{-- Colonne principale --}}
        <div class="col-lg-8">
            <div class="ag-card mb-3">
                <div class="ag-card__body">
                    <div class="mb-3">
                        <label for="ag-title" class="form-label">Titre</label>
                        <input type="text" id="ag-title" name="title" class="form-control form-control-lg"
                               value="{{ $article->title }}" required
                               style="font-size:1.05rem;font-weight:550;">
                        <div class="ag-hint mt-1" id="ag-title-counter"
                             data-max="{{ $settings->threshold('title_max_words', 20) }}"></div>
                    </div>

                    <div>
                        <label for="ag-slug" class="form-label">URL / slug</label>
                        <div class="input-group">
                            <span class="input-group-text ag-mono small">
                                {{ rtrim(parse_url($site->url, PHP_URL_HOST) ?? $site->url, '/') }}/
                            </span>
                            <input type="text" id="ag-slug" name="slug" class="form-control ag-mono"
                                   value="{{ $article->slug }}">
                        </div>
                        <div class="ag-hint mt-1">
                            Modifier le slug change l’adresse publique de l’article sur WordPress.
                        </div>
                    </div>
                </div>
            </div>

            {{-- Éditeur : Visuel / Code source --}}
            <div class="ag-card">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Contenu</h2>
                    <div class="ag-tabs ms-auto" role="tablist" aria-label="Mode d’édition">
                        <button type="button" class="is-active" data-editor-tab="visual" role="tab">Visuel</button>
                        <button type="button" data-editor-tab="source" role="tab">Code source</button>
                    </div>
                </div>

                <div class="ag-card__body">
                    <div id="ag-editor" class="ag-editor">
                        <div class="ag-editor__toolbar" role="toolbar" aria-label="Mise en forme">
                            {{-- Type du bloc courant, comme le sélecteur de bloc de WordPress. --}}
                            <select class="form-select form-select-sm ag-editor__block-select" data-editor-block
                                    aria-label="Type de bloc" title="Type du bloc sélectionné">
                                <option value="p">Paragraphe</option>
                                <option value="h1">Titre 1 (H1)</option>
                                <option value="h2">Titre 2 (H2)</option>
                                <option value="h3">Titre 3 (H3)</option>
                                <option value="h4">Titre 4 (H4)</option>
                                <option value="h5">Titre 5 (H5)</option>
                                <option value="h6">Titre 6 (H6)</option>
                                <option value="blockquote">Citation</option>
                                <option value="pre">Préformaté</option>
                                <option value="" disabled hidden data-editor-block-other>Autre</option>
                            </select>
                            <span class="vr mx-1"></span>
                            <button type="button" class="ag-editor__tool" data-command="bold"
                                    title="Gras" aria-label="Gras"><i class="bi bi-type-bold" aria-hidden="true"></i></button>
                            <button type="button" class="ag-editor__tool" data-command="italic"
                                    title="Italique" aria-label="Italique"><i class="bi bi-type-italic" aria-hidden="true"></i></button>
                            <span class="vr mx-1"></span>
                            <button type="button" class="ag-editor__tool" data-command="formatBlock" data-value="h2"
                                    title="Titre H2" aria-label="Titre H2"><i class="bi bi-type-h2" aria-hidden="true"></i></button>
                            <button type="button" class="ag-editor__tool" data-command="formatBlock" data-value="h3"
                                    title="Titre H3" aria-label="Titre H3"><i class="bi bi-type-h3" aria-hidden="true"></i></button>
                            <button type="button" class="ag-editor__tool" data-command="formatBlock" data-value="p"
                                    title="Paragraphe" aria-label="Paragraphe"><i class="bi bi-paragraph" aria-hidden="true"></i></button>
                            <span class="vr mx-1"></span>
                            <button type="button" class="ag-editor__tool" data-command="insertUnorderedList"
                                    title="Liste à puces" aria-label="Liste à puces"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
                            <button type="button" class="ag-editor__tool" data-command="insertOrderedList"
                                    title="Liste numérotée" aria-label="Liste numérotée"><i class="bi bi-list-ol" aria-hidden="true"></i></button>
                            <span class="vr mx-1"></span>
                            <button type="button" class="ag-editor__tool" data-command="createLink"
                                    title="Insérer un lien" aria-label="Insérer un lien"><i class="bi bi-link-45deg" aria-hidden="true"></i></button>
                            <button type="button" class="ag-editor__tool" data-editor-insert-image
                                    title="Insérer une image" aria-label="Insérer une image"><i class="bi bi-image" aria-hidden="true"></i></button>
                            <span class="vr mx-1"></span>
                            <button type="button" class="ag-editor__tool" data-command="removeFormat"
                                    title="Effacer la mise en forme" aria-label="Effacer la mise en forme"><i class="bi bi-eraser" aria-hidden="true"></i></button>
                        </div>

                        {{-- Structure des titres : nombre de H1…H6 et plan cliquable. --}}
                        <div class="ag-outline" data-editor-outline>
                            <div class="ag-outline__bar">
                                <span class="ag-outline__label">Titres</span>
                                <div class="ag-outline__counts" data-outline-counts></div>
                                <button type="button" class="ag-outline__toggle" data-outline-toggle
                                        aria-expanded="false" aria-controls="ag-outline-list">
                                    Plan (<span data-outline-total>0</span>)
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>
                            </div>
                            <ol class="ag-outline__list" id="ag-outline-list" data-outline-list hidden></ol>
                        </div>

                        {{-- La surface visuelle est remplie par le JavaScript à partir du
                             code source, après assainissement : le HTML d'un site tiers
                             n'est jamais injecté tel quel dans la page. --}}
                        <div class="ag-editor__surface" data-editor-surface contenteditable="true"
                             role="textbox" aria-multiline="true" aria-label="Contenu de l’article"></div>

                        <textarea class="ag-editor__source" data-editor-source hidden
                                  aria-label="Code source HTML de l’article">{{ $article->content }}</textarea>
                    </div>

                    <p class="ag-hint mt-2 mb-0">
                        L’onglet « Code source » fait foi : c’est ce HTML qui est envoyé à WordPress.
                    </p>
                </div>
            </div>
        </div>

        {{-- Colonne latérale --}}
        <div class="col-lg-4">
            {{-- Prise en charge : qui travaille sur l'article. --}}
            @include('articles.partials.lock-panel')

            {{-- Audit --}}
            <div class="ag-card mb-3">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Audit de l’article</h2>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto"
                            id="ag-run-audit" data-url="{{ route('articles.audit', $article) }}">
                        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i> Relancer
                    </button>
                </div>
                <div class="ag-card__body" id="ag-audit-panel">
                    @include('articles.partials.audit-panel', ['article' => $article, 'issues' => $issues])
                </div>
            </div>

            {{-- Publication --}}
            <div class="ag-card mb-3">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Publication</h2>
                </div>
                <div class="ag-card__body">
                    <div class="mb-3">
                        <label for="ag-status-select" class="form-label">Statut</label>
                        <select id="ag-status-select" name="status" class="form-select">
                            @foreach(['publish' => 'Publié', 'draft' => 'Brouillon', 'pending' => 'En attente de relecture', 'private' => 'Privé'] as $value => $label)
                                <option value="{{ $value }}" @selected($article->status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <dl class="row small mb-0">
                        <dt class="col-5 fw-normal ag-muted">Publié le</dt>
                        <dd class="col-7">{{ $article->wordpress_published_at?->translatedFormat('d M Y') ?? '—' }}</dd>
                        <dt class="col-5 fw-normal ag-muted">Modifié le</dt>
                        <dd class="col-7">{{ $article->wordpress_modified_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                        <dt class="col-5 fw-normal ag-muted">Synchronisé</dt>
                        <dd class="col-7">{{ $article->synced_at?->diffForHumans() ?? '—' }}</dd>
                    </dl>
                </div>
                <div class="ag-card__body border-top d-grid gap-2">
                    @if($canEdit)
                        <button type="submit" class="btn btn-primary" data-save
                                @disabled(! $site->hasCredentials())>
                            <i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i> Mettre à jour
                        </button>
                    @else
                        <p class="ag-hint mb-0 text-center">
                            <i class="bi bi-lock me-1" aria-hidden="true"></i>
                            Consultation seule : prenez l’article pour le modifier.
                        </p>
                    @endif
                </div>
            </div>

            {{-- Image mise en avant --}}
            <div class="ag-card mb-3" id="ag-featured">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Image mise en avant</h2>
                </div>
                <div class="ag-card__body">
                    <input type="hidden" name="featured_media_id" value="{{ $article->featured_media_id }}">

                    <img class="ag-thumb mb-2" data-featured-preview
                         src="{{ $article->featured_media_url }}"
                         alt="{{ $article->featured_media_alt }}"
                         @if(! $article->featured_media_url) hidden @endif>

                    <div class="ag-empty py-4 mb-2" data-featured-empty
                         @if($article->featured_media_url) hidden @endif>
                        <div class="ag-empty__icon"><i class="bi bi-image" aria-hidden="true"></i></div>
                        <p class="ag-hint mb-0">Aucune image à la une</p>
                    </div>

                    {{-- Champs du fichier joint WordPress : ils décrivent le média
                         lui-même, pas cet article. Renseignés ici, ils suivent
                         l'image partout où elle est utilisée. --}}
                    <dl class="ag-image-row__summary mb-2" data-featured-summary
                        @if(! $article->featured_media_url) hidden @endif>
                        <dt>Texte alternatif</dt>
                        <dd data-featured-alt class="{{ $article->featured_media_alt ? '' : 'is-missing' }}">
                            {{ $article->featured_media_alt ?: 'Non renseigné' }}
                        </dd>
                    </dl>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary flex-grow-1"
                                data-featured-replace>
                            {{ $article->featured_media_url ? 'Remplacer' : 'Choisir une image' }}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-featured-remove
                                @if(! $article->featured_media_url) hidden @endif>
                            Retirer
                        </button>
                    </div>

                    <button type="button" class="btn btn-sm btn-link w-100 mt-1 p-0 text-decoration-none"
                            data-featured-details @if(! $article->featured_media_id) hidden @endif>
                        <i class="bi bi-sliders me-1" aria-hidden="true"></i>
                        Détails de l’image (texte alternatif, titre, légende, description, URL)
                    </button>
                </div>
            </div>

            {{-- Catégories --}}
            <div class="ag-card mb-3">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Catégories</h2>
                </div>
                <div class="ag-card__body">
                    @forelse($categories as $category)
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="categories[]"
                                   value="{{ $category->id }}"
                                   @checked(in_array($category->id, $selectedCategories, true))>
                            <span class="form-check-label small">{{ $category->name }}</span>
                        </label>
                    @empty
                        <p class="ag-hint mb-0">Aucune catégorie synchronisée pour ce site.</p>
                    @endforelse
                </div>
            </div>

            {{-- Images du contenu --}}
            <div class="ag-card">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Images du contenu</h2>
                </div>
                <div class="ag-card__body" id="ag-content-images"></div>
            </div>
        </div>
    </div>
</form>

@include('articles.partials.media-modal', ['site' => $site])
@endsection
