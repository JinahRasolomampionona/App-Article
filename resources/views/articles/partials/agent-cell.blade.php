@php
    /** @var \App\Models\WordpressArticle $article */
    $me = auth()->user();
    $state = $article->lockStateFor($me);
    $holderId = $article->activeAgentId();

    // Agents actifs, plus soi-même et le détenteur actuel s'ils n'y figurent
    // pas (un Admin, un compte désactivé depuis).
    // Seuls les agents ayant connecté ce site peuvent y travailler.
    $options = \App\Support\AgentCatalog::forSite($article->wordpress_site_id)->keyBy('id');
    if (! $options->has($me->id)) {
        $options->put($me->id, $me);
    }
    if ($holderId && ! $options->has($holderId) && $article->assignee) {
        $options->put($holderId, $article->assignee);
    }
    $options = $options->sortBy('name');

    $isAdmin = $me->isAdmin();
@endphp

{{-- Statut de traitement, distinct du statut d'audit. Les choix proposés
     reflètent les droits ; le serveur les revérifie de toute façon. --}}
<div class="ag-agent" data-lock-key="{{ $state }}:{{ $holderId ?? 0 }}">
    <select class="form-select form-select-sm ag-agent-select ag-agent-select--{{ $state }}"
            data-agent-url="{{ route('articles.agent', $article) }}"
            aria-label="Agent chargé de l’article {{ $article->title }}"
            @disabled($state === 'other' && ! $isAdmin)>
        <option value="" @selected($holderId === null) @disabled($state === 'other' && ! $isAdmin)>Non assigné</option>
        @foreach($options as $option)
            <option value="{{ $option->id }}" @selected($holderId === $option->id)
                    @disabled(! $isAdmin && $option->id !== $me->id)>
                {{ $option->name }}@if($option->id === $me->id) (vous)@endif
            </option>
        @endforeach
    </select>

    @switch($state)
        @case('mine')
            <span class="ag-lock ag-lock--mine">
                <span class="ag-lock__dot" aria-hidden="true"></span> En cours par vous
            </span>
            @break
        @case('other')
            <span class="ag-lock ag-lock--other" data-bs-toggle="tooltip"
                  title="Pris {{ $article->locked_at?->diffForHumans() }}">
                <span class="ag-lock__dot" aria-hidden="true"></span> En cours par {{ $article->activeAgentName() }}
            </span>
            @break
        @default
            <span class="ag-lock ag-lock--available">
                <span class="ag-lock__dot" aria-hidden="true"></span> Disponible
            </span>
    @endswitch
</div>
