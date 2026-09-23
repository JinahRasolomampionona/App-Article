{{-- Sélecteur de média, partagé par l'image mise en avant, les images du
     contenu et l'insertion depuis la barre d'outils.

     Le panneau de droite reprend « Détails du fichier joint » de WordPress :
     les champs y appartiennent au média lui-même et sont enregistrés dans la
     médiathèque, pas dans l'article. --}}

<div class="modal fade" id="ag-media-modal" tabindex="-1"
     aria-labelledby="ag-media-heading" aria-hidden="true"
     data-index-url="{{ route('sites.media.index', $site) }}"
     data-store-url="{{ route('sites.media.store', $site) }}"
     @if(! $site->hasCredentials()) data-read-only="1" @endif>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6 mb-0" id="ag-media-heading">Médiathèque WordPress</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body">
                <div class="ag-media-layout">
                    <div class="ag-media-layout__browse">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <input type="search" class="form-control flex-grow-1" data-media-search
                                   placeholder="Rechercher une image…" aria-label="Rechercher dans la médiathèque"
                                   style="max-width: 22rem;">

                            <button type="button" class="btn btn-outline-secondary" data-media-upload-trigger>
                                <i class="bi bi-upload me-1" aria-hidden="true"></i> Téléverser
                            </button>
                            <input type="file" class="d-none" data-media-upload accept="image/*">
                        </div>

                        <div class="ag-media-grid" data-media-grid></div>
                    </div>

                    <aside class="ag-media-details" data-media-details aria-label="Détails du fichier joint">
                        <p class="ag-media-details__title">Détails du fichier joint</p>

                        <div class="ag-media-details__empty" data-media-details-empty>
                            <p class="ag-hint mb-0">
                                Sélectionnez une image pour afficher et modifier ses détails.
                            </p>
                        </div>

                        <div data-media-details-body hidden>
                            <div class="ag-media-details__head">
                                <img data-media-details-thumb src="" alt="" loading="lazy">
                                <div class="min-w-0">
                                    <span class="ag-media-details__file ag-mono" data-media-details-filename></span>
                                    <span class="ag-hint d-block" data-media-details-dimensions></span>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small mb-1" for="ag-media-alt">Texte alternatif</label>
                                <textarea id="ag-media-alt" class="form-control form-control-sm" rows="2"
                                          data-media-field="alt_text"
                                          placeholder="Décrivez l’image pour les lecteurs d’écran"></textarea>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small mb-1" for="ag-media-title">Titre</label>
                                <input type="text" id="ag-media-title" class="form-control form-control-sm"
                                       data-media-field="title">
                            </div>

                            <div class="mb-2">
                                <label class="form-label small mb-1" for="ag-media-caption">Légende de l’image</label>
                                <textarea id="ag-media-caption" class="form-control form-control-sm" rows="2"
                                          data-media-field="caption"></textarea>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small mb-1" for="ag-media-description">Description</label>
                                <textarea id="ag-media-description" class="form-control form-control-sm" rows="3"
                                          data-media-field="description"></textarea>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small mb-1" for="ag-media-url">URL de fichier</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" id="ag-media-url" class="form-control ag-mono"
                                           data-media-details-url readonly>
                                    <button type="button" class="btn btn-outline-secondary" data-media-copy-url
                                            title="Copier l’URL" aria-label="Copier l’URL du fichier">
                                        <i class="bi bi-clipboard" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-secondary w-100"
                                    data-media-details-save>
                                <i class="bi bi-save me-1" aria-hidden="true"></i> Enregistrer les détails
                            </button>

                            <p class="ag-hint mt-2 mb-0" data-media-details-note>
                                Ces informations sont enregistrées dans la médiathèque WordPress et
                                s’appliquent partout où l’image est utilisée.
                            </p>
                        </div>
                    </aside>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-sm btn-primary" data-media-confirm disabled>
                    Utiliser cette image
                </button>
            </div>
        </div>
    </div>
</div>

@include('articles.partials.image-details-modal')
