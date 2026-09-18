@php
    use App\Support\AuditPanelPresenter;

    $settings = $settings ?? \App\Services\Audit\AuditSettings::forUser(auth()->user());
    $checks = AuditPanelPresenter::checks(collect($issues), $settings);
    $openCount = collect($issues)->count();
@endphp

<div class="d-flex align-items-center gap-2 mb-3">
    @if($openCount === 0 && $article->last_audited_at)
        <span class="ag-badge ag-badge--success">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Aucun problème
        </span>
    @elseif($openCount > 0)
        <span class="ag-badge ag-badge--danger">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            {{ $openCount }} problème(s)
        </span>
    @else
        <span class="ag-badge ag-badge--muted">Jamais audité</span>
    @endif

    <span class="ag-hint ms-auto">
        {{ $article->last_audited_at ? 'Analysé '.$article->last_audited_at->diffForHumans() : '' }}
    </span>
</div>

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

    <button type="button"
            class="ag-audit-item ag-audit-item--{{ $variant }}"
            data-audit-target="{{ $check['target'] }}"
            data-audit-type="{{ $check['type'] }}"
            @disabled($check['state'] === 'disabled' || $check['state'] === 'ok')>
        <span class="ag-audit-item__icon" aria-hidden="true">
            <i class="bi {{ $icon }}"></i>
        </span>
        <span class="flex-grow-1">
            <span class="d-block fw-medium">{{ $check['label'] }}</span>

            @if($check['state'] === 'disabled')
                <span class="ag-audit-item__meta">Règle désactivée dans les paramètres</span>
            @elseif($check['issues'])
                @foreach($check['issues'] as $issue)
                    <span class="ag-audit-item__meta d-block">{{ $issue->message }}</span>
                @endforeach
            @else
                <span class="ag-audit-item__meta">Conforme</span>
            @endif
        </span>
    </button>
@endforeach
