{{-- Synchronisations et audits passent par la file d'attente. Sans worker,
     ils ne s'exécutent jamais : l'utilisateur doit le savoir immédiatement
     plutôt que d'attendre des articles qui n'arriveront pas. --}}

@if(! empty($queueWarning))
    <div class="alert alert-warning d-flex gap-3 align-items-start py-2 px-3 small" role="alert">
        <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
        <div>
            <strong class="d-block mb-1">Les tâches en arrière-plan ne sont pas traitées</strong>
            <p class="mb-1">{{ $queueWarning }}</p>
            <p class="mb-0 ag-mono">php artisan queue:work</p>
        </div>
    </div>
@endif
