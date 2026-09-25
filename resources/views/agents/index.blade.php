@extends('layouts.app')

@section('title', 'Agents')
@section('heading', 'Agents')
@section('subheading', 'Comptes de l’espace de travail : chaque agent se connecte avec ses propres identifiants.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <span class="active">Agents</span>
@endsection

@section('actions')
    <a href="{{ route('agents.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Nouvel agent
    </a>
@endsection

@section('content')
<div class="ag-card">
    <div class="table-responsive">
        <table class="table ag-table align-middle">
            <thead>
                <tr>
                    <th scope="col">Nom</th>
                    <th scope="col">Rôle</th>
                    <th scope="col">Statut</th>
                    <th scope="col" class="text-end">En cours</th>
                    <th scope="col" class="text-end">Corrections terminées</th>
                    <th scope="col">Créé le</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($users as $user)
                <tr @class(['ag-row-archived' => ! $user->is_active])>
                    <td>
                        <span class="ag-table__title">
                            {{ $user->name }}
                            @if($user->id === auth()->id())
                                <span class="ag-hint">(vous)</span>
                            @endif
                        </span>
                        <span class="ag-table__url">{{ $user->email }}</span>
                    </td>
                    <td>
                        <span class="ag-badge ag-badge--{{ $user->isAdmin() ? 'primary' : 'neutral' }}">
                            {{ $user->roleLabel() }}
                        </span>
                    </td>
                    <td>
                        @if($user->is_active)
                            <span class="ag-badge ag-badge--success">Actif</span>
                        @else
                            <span class="ag-badge ag-badge--muted">Désactivé</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @if($user->in_progress_count > 0)
                            <a href="{{ route('articles.index', ['agent' => $user->id]) }}" class="text-decoration-none">
                                {{ $user->in_progress_count }}
                            </a>
                        @else
                            <span class="ag-hint">0</span>
                        @endif
                    </td>
                    <td class="text-end">{{ $user->completed_count }}</td>
                    <td><span class="ag-hint">{{ $user->created_at?->translatedFormat('d/m/Y') }}</span></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <a href="{{ route('statistics.index', ['agent' => $user->id]) }}"
                               class="btn btn-sm btn-outline-secondary"
                               data-bs-toggle="tooltip" title="Statistiques"
                               aria-label="Statistiques de {{ $user->name }}">
                                <i class="bi bi-bar-chart-line" aria-hidden="true"></i>
                            </a>
                            <a href="{{ route('agents.edit', $user) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
                            @can('delete', $user)
                                <form method="POST" action="{{ route('agents.destroy', $user) }}"
                                      data-confirm="Supprimer le compte de {{ $user->name }} ? Ses articles en cours seront libérés ; son historique de corrections est conservé.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            aria-label="Supprimer le compte de {{ $user->name }}">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <p class="ag-hint mb-0 py-3 text-center">Aucun compte.</p>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
