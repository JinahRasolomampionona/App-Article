{{-- « Détails de l'image » pour une image du contenu.

     Contrairement au panneau de la médiathèque, ces réglages ne concernent que
     cette occurrence de l'image dans l'article : ils sont écrits dans le HTML
     envoyé à WordPress. --}}

<div class="modal fade" id="ag-image-details-modal" tabindex="-1"
     aria-labelledby="ag-image-details-heading" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6 mb-0" id="ag-image-details-heading">Détails de l’image</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body">
                <div class="ag-image-details">
                    <div class="ag-image-details__preview">
                        <img data-details-preview src="" alt="" loading="lazy">
                        <span class="ag-hint d-block mt-2 text-break ag-mono" data-details-filename></span>
                    </div>

                    <div class="ag-image-details__fields">
                        <div class="mb-3">
                            <label class="form-label small mb-1" for="ag-details-alt">Texte alternatif</label>
                            <textarea id="ag-details-alt" class="form-control form-control-sm" rows="2"
                                      data-details-alt
                                      placeholder="Décrivez l’image pour les lecteurs d’écran"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small mb-1" for="ag-details-link">Lien</label>
                            <div class="input-group input-group-sm">
                                <input type="url" id="ag-details-link" class="form-control"
                                       placeholder="https://…" data-details-link>
                                <a class="btn btn-outline-secondary" target="_blank" rel="noopener noreferrer"
                                   data-details-link-open title="Ouvrir le lien"
                                   aria-label="Ouvrir le lien dans un nouvel onglet">
                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                </a>
                            </div>
                            <div class="ag-hint mt-1">
                                Laisser vide pour que l’image ne soit pas cliquable.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small mb-1" for="ag-details-caption">Légende</label>
                            <textarea id="ag-details-caption" class="form-control form-control-sm" rows="2"
                                      data-details-caption></textarea>
                        </div>

                        <p class="ag-image-details__section">Réglages de l’affichage pour l’image</p>

                        <div class="mb-3">
                            <span class="form-label small mb-1 d-block" id="ag-details-align-label">Alignement</span>
                            <div class="btn-group btn-group-sm w-100" role="group"
                                 aria-labelledby="ag-details-align-label">
                                @foreach([
                                    'none' => ['Aucun', 'bi-slash-circle'],
                                    'left' => ['Gauche', 'bi-text-left'],
                                    'center' => ['Centre', 'bi-text-center'],
                                    'right' => ['Droite', 'bi-text-right'],
                                ] as $value => $option)
                                    <input type="radio" class="btn-check" name="ag-details-align"
                                           id="ag-details-align-{{ $value }}" value="{{ $value }}"
                                           data-details-align autocomplete="off">
                                    <label class="btn btn-outline-secondary" for="ag-details-align-{{ $value }}">
                                        <i class="bi {{ $option[1] }} me-1" aria-hidden="true"></i>{{ $option[0] }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="row g-2 align-items-end">
                            <div class="col-12">
                                <label class="form-label small mb-1" for="ag-details-size">Taille</label>
                                <select id="ag-details-size" class="form-select form-select-sm" data-details-size>
                                    <option value="custom">Taille personnalisée</option>
                                </select>
                            </div>

                            <div class="col-6">
                                <label class="form-label small mb-1" for="ag-details-width">Largeur (px)</label>
                                <input type="number" id="ag-details-width" class="form-control form-control-sm"
                                       min="1" max="10000" step="1" data-details-width>
                            </div>

                            <div class="col-6">
                                <label class="form-label small mb-1" for="ag-details-height">Hauteur (px)</label>
                                <input type="number" id="ag-details-height" class="form-control form-control-sm"
                                       min="1" max="10000" step="1" data-details-height>
                            </div>
                        </div>

                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="ag-details-ratio"
                                   data-details-ratio checked>
                            <label class="form-check-label small" for="ag-details-ratio">
                                Conserver les proportions
                            </label>
                        </div>

                        <div class="ag-hint mt-2" data-details-sizes-note hidden>
                            Les tailles proposées sont celles générées par WordPress pour ce média.
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-danger me-auto" data-details-remove>
                    <i class="bi bi-trash me-1" aria-hidden="true"></i> Retirer l’image
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-details-replace>
                    <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Remplacer
                </button>
                <button type="button" class="btn btn-sm btn-primary" data-details-apply>Appliquer</button>
            </div>
        </div>
    </div>
</div>
