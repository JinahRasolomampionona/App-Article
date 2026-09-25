@extends('layouts.app')

@section('title', 'Nouvel agent')
@section('heading', 'Créer un compte')
@section('subheading', 'Chaque agent dispose de son propre compte pour prendre et corriger les articles.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <a href="{{ route('agents.index') }}">Agents</a> <span class="mx-1">/</span>
    <span class="active">Nouveau</span>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-7">
        <div class="ag-card">
            <div class="ag-card__body">
                <form method="POST" action="{{ route('agents.store') }}" novalidate>
                    @csrf
                    @include('agents._form', ['agent' => null])

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Créer le compte</button>
                        <a href="{{ route('agents.index') }}" class="btn btn-outline-secondary">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
