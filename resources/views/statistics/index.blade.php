@extends('layouts.app')

@section('title', 'Statistiques')
@section('heading', 'Statistiques')
@section('subheading', 'État des articles, activité des agents et des sites.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <span class="active">Statistiques</span>
@endsection

@section('content')
    @include('statistics._filters')

    {{-- Mêmes cartes que le Dashboard : chacune ouvre « Articles » filtré. --}}
    @include('partials.status-cards', [
        'cards' => $cards,
        'linkSite' => $siteFilter,
        'scope' => $siteFilter ? null : 'tous sites confondus',
    ])

    @include('statistics.partials.per-agent')
    @include('statistics.partials.per-site')
@endsection
