@php
    use App\Support\AuditPanelPresenter;

    $settings = $settings ?? \App\Services\Audit\AuditSettings::forUser(auth()->user());
    $issues = collect($issues);
    $checks = AuditPanelPresenter::checks($issues, $settings);
    $summary = AuditPanelPresenter::summary($issues);
    $openCount = $issues->count();
@endphp

<div class="d-flex align-items-center gap-2 mb-2">
    @if($openCount === 0 && $article->last_audited_at)
        <span class="ag-badge ag-badge--success">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Aucun problème
        </span>
    @elseif($openCount > 0)
        <span class="ag-badge ag-badge--danger">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            {{ $openCount }} {{ $openCount > 1 ? 'problèmes' : 'problème' }}
        </span>
    @else
        <span class="ag-badge ag-badge--muted">Jamais audité</span>
    @endif

    <span class="ag-hint ms-auto">
        {{ $article->last_audited_at ? 'Analysé '.$article->last_audited_at->diffForHumans() : '' }}
    </span>
</div>

{{-- Résumé : la nature de chaque problème, sans avoir à parcourir la liste. --}}
@if($summary)
    <ul class="ag-audit-summary">
        @foreach($summary as $entry)
            <li class="ag-audit-summary__item ag-audit-summary__item--{{ $entry['severity'] }}">
                @if($entry['count'] > 1)<strong>{{ $entry['count'] }} ×</strong>@endif
                {{ $entry['message'] }}
            </li>
        @endforeach
    </ul>
@endif

@foreach($checks as $check)
    @php
        $icon = match ($check['state']) {
            'ok' => 'bi-check-lg',
            'error' => 'bi-x-lg',
            'disabled' => 'bi-dash-lg',
            default => 'bi-exclamation-lg',
        };
        $variant = match ($check['state']) {
            'ok' => 'ok',
            'error' => 'error',
            'info' => 'info',
            'disabled' => 'ok',
            default => 'warning',
        };
    @endphp

    <div class="ag-audit-item ag-audit-item--{{ $variant }} {{ $check['issues'] ? 'has-issues' : '' }}">
        <span class="ag-audit-item__icon" aria-hidden="true">
            <i class="bi {{ $icon }}"></i>
        </span>
        <div class="flex-grow-1 min-w-0">
            <span class="d-flex align-items-center gap-2 fw-medium">
                {{ $check['label'] }}
                @if(count($check['issues']) > 1)
                    <span class="ag-audit-item__count">{{ count($check['issues']) }}</span>
                @endif
            </span>

            @if($check['state'] === 'disabled')
                <span class="ag-audit-item__meta">Règle désactivée dans les paramètres</span>
            @elseif($check['issues'])
                @foreach($check['issues'] as $issue)
                    @php $detail = AuditPanelPresenter::describe($issue, $settings); @endphp

                    {{-- Chaque problème mène à la zone concernée (image, titre, contenu). --}}
                    <button type="button" class="ag-audit-issue"
                            data-audit-target="{{ $detail['target'] }}"
                            data-audit-type="{{ $issue->rule_type }}"
                            @if($detail['src']) data-audit-src="{{ $detail['src'] }}" @endif>
                        <span class="ag-audit-issue__title">{{ $detail['title'] }}</span>

                        @if($detail['src'])
                            <span class="ag-audit-issue__image">
                                <img src="{{ $detail['src'] }}" alt="" loading="lazy">
                                <span class="min-w-0">
                                    @if($detail['where'])
                                        <span class="ag-audit-issue__where">{{ $detail['where'] }}</span>
                                    @endif
                                    <span class="ag-audit-issue__file ag-mono" title="{{ $detail['src'] }}">{{ $detail['file'] }}</span>
                                </span>
                            </span>
                        @endif

                        @foreach($detail['details'] as $line)
                            <span class="ag-audit-issue__line">{{ $line }}</span>
                        @endforeach

                        @if($detail['hint'])
                            <span class="ag-audit-issue__hint">
                                <i class="bi bi-lightbulb" aria-hidden="true"></i> {{ $detail['hint'] }}
                            </span>
                        @endif
                    </button>
                @endforeach
            @else
                <span class="ag-audit-item__meta">Conforme</span>
            @endif
        </div>
    </div>
@endforeach
