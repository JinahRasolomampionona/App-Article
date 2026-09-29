{{-- Admin : réassigner un article à un agent, ou lui laisser un commentaire.

     Ouvert par un bouton `data-reassign-url` (agent + commentaire facultatif)
     ou `data-note-url` (commentaire seul). Seuls les agents assignés au site
     de l'article sont proposés ; le serveur le revérifie. --}}
@php
    $agentSites = \App\Models\SiteAgentAssignment::query()
        ->where('status', \App\Models\SiteAgentAssignment::STATUS_IN_PROGRESS)
        ->get(['user_id', 'wordpress_site_id'])
        ->groupBy('user_id')
        ->map(fn ($rows) => $rows->pluck('wordpress_site_id')->implode(','));
@endphp

<div class="modal fade" id="ag-reassign-modal" tabindex="-1" aria-labelledby="ag-reassign-heading" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" data-reassign-form novalidate>
            <div class="modal-header">
                <div class="min-w-0">
                    <h2 class="modal-title h6 mb-0" id="ag-reassign-heading" data-reassign-heading>Réassigner l’article</h2>
                    <p class="ag-hint mb-0 text-truncate" data-reassign-article></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3" data-reassign-agent-group>
                    <label for="ag-reassign-agent" class="form-label">Agent</label>
                    <select id="ag-reassign-agent" name="agent" class="form-select">
                        <option value="">Choisir un agent…</option>
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" data-sites="{{ $agentSites[$agent->id] ?? '' }}">{{ $agent->name }}</option>
                        @endforeach
                    </select>
                    <p class="ag-hint mt-1 mb-0">L’article réapparaît dans la liste de l’agent, « En cours » à son nom.</p>
                </div>

                <div>
                    <label for="ag-reassign-comment" class="form-label">
                        Commentaire <span class="ag-hint" data-reassign-optional>(facultatif)</span>
                    </label>
                    <textarea id="ag-reassign-comment" name="comment" class="form-control" rows="4" maxlength="2000"
                              placeholder="Ex. : l’image à la une est encore floue, remplacez-la."></textarea>
                    <p class="ag-hint mt-1 mb-0">Visible par l’agent dans le tableau et dans l’éditeur.</p>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-sm btn-primary" data-reassign-submit>Réassigner</button>
            </div>
        </form>
    </div>
</div>
