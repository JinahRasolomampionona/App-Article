@extends('layouts.app')

@section('title', 'Modifier un compte')
@section('heading', 'Modifier le compte')
@section('subheading', $agent->name.' · '.$agent->roleLabel())

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <a href="{{ route('agents.index') }}">Agents</a> <span class="mx-1">/</span>
    <span class="active">{{ $agent->name }}</span>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-7">
        <div class="ag-card">
            <div class="ag-card__body">
                <form method="POST" action="{{ route('agents.update', $agent) }}" novalidate>
                    @csrf
                    @method('PUT')
                    @include('agents._form', ['agent' => $agent])

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                        <a href="{{ route('agents.index') }}" class="btn btn-outline-secondary">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
