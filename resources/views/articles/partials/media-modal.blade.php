{{-- Sélecteur de média, partagé par l'image mise en avant, les images du
     contenu et l'insertion depuis la barre d'outils. --}}

<div class="modal fade" id="ag-media-modal" tabindex="-1"
     aria-labelledby="ag-media-heading" aria-hidden="true"
     data-index-url="{{ route('sites.media.index', $site) }}"
     data-store-url="{{ route('sites.media.store', $site) }}">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6 mb-0" id="ag-media-heading">Médiathèque WordPress</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body">
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

            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-sm btn-primary" data-media-confirm disabled>
                    Utiliser cette image
                </button>
            </div>
        </div>
    </div>
</div>
