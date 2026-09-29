@php
    /** @var \App\Models\WordpressArticle $article */
    $me = auth()->user();
    $isAdmin = $me->isAdmin();
    // Un agent ne touche pas au statut d'un article qu'un autre traite.
    $lockedByOther = $article->isLockedByOther($me) && ! $isAdmin;
    // « Corrigé » exige un agent assigné : c'est lui qui en est crédité.
    $needsAgent = ! $article->isLocked();
@endphp

{{-- Statut : « À corriger » (problèmes détectés) ou « À vérifier » (aucun
     problème détecté), que l'agent assigné passe à « Corrigé » une fois
     l'article traité. Un article corrigé attend la vérification de l'Admin. --}}
<div data-status-key="{{ $article->displayStatus() }}:{{ $article->activeAgentId() ?? 0 }}">
    @if($article->statusIsEditable())
        <select class="form-select form-select-sm ag-status-select ag-status-select--{{ $article->statusVariant() }}"
                data-status-url="{{ route('articles.status', $article) }}"
                aria-label="Statut de l’article {{ $article->title }}"
                @disabled($lockedByOther)
                @if($lockedByOther) title="En cours par {{ $article->activeAgentName() }}" @endif>
            @foreach($article->statusOptions() as $value => $label)
                @php $isDone = $value === \App\Models\WordpressArticle::STATUS_DONE; @endphp
                <option value="{{ $value }}" @selected(! $isDone) @disabled($isDone && $needsAgent)>
                    {{ $label }}{{ $isDone && $needsAgent ? ' (assignez un agent)' : '' }}
                </option>
            @endforeach
        </select>
    @elseif($article->isCompleted())
        <span class="ag-badge ag-badge--success" data-bs-toggle="tooltip"
              title="Déclaré corrigé le {{ $article->completed_at->translatedFormat('d/m/Y à H:i') }}">
            <i class="bi bi-check2-circle" aria-hidden="true"></i> Corrigé
        </span>
        @if($article->completer)
            <span class="ag-hint d-block mt-1">par {{ $article->completer->name }}</span>
        @endif
    @elseif($article->audit_status === \App\Models\WordpressArticle::AUDIT_PENDING)
        {{-- Pas encore audité : ne pas laisser croire à un article sans problème. --}}
        <span class="ag-badge ag-badge--muted" data-bs-toggle="tooltip"
              title="L’audit de cet article est en attente ou en cours.">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i> En attente d’analyse
        </span>
    @else
        <span class="ag-hint">—</span>
    @endif
</div>
