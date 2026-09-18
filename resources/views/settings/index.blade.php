@extends('layouts.app')

@section('title', 'Paramètres')
@section('heading', 'Paramètres')
@section('subheading', 'Règles d’audit et seuils de détection.')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> <span class="mx-1">/</span>
    <span class="active">Paramètres</span>
@endsection

@section('content')
<form method="POST" action="{{ route('settings.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="ag-card">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Règles d’audit</h2>
                </div>
                <div class="ag-card__body">
                    @foreach($catalog as $rule)
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="rule-{{ $rule['key'] }}"
                                   name="rules[{{ $rule['key'] }}]" value="1"
                                   @checked($settings->ruleEnabled($rule['key']))>
                            <label class="form-check-label" for="rule-{{ $rule['key'] }}">
                                {{ $rule['label'] }}
                                @if($rule['requires_network'])
                                    <span class="ag-chip ms-1" data-bs-toggle="tooltip"
                                          title="Télécharge les images : exécutée en file d’attente">
                                        réseau
                                    </span>
                                @endif
                                @if($rule['key'] === 'image_relevance' && ! $relevanceAvailable)
                                    <span class="ag-badge ag-badge--muted ms-1">
                                        aucun fournisseur configuré
                                    </span>
                                @endif
                            </label>
                        </div>
                    @endforeach

                    <p class="ag-hint mb-0">
                        Désactiver une règle n’efface pas les problèmes déjà détectés : ils restent
                        ouverts tant qu’un audit exécutant cette règle ne les a pas revus.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="ag-card mb-3">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Seuils</h2>
                </div>
                <div class="ag-card__body">
                    <div class="mb-3">
                        <label for="title_max_words" class="form-label">Longueur maximale du H1 (titre)</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="title_max_words"
                                   name="thresholds[title_max_words]" min="3" max="60"
                                   value="{{ old('thresholds.title_max_words', $settings->threshold('title_max_words', 20)) }}">
                            <span class="input-group-text">mots</span>
                        </div>
                        @error('thresholds.title_max_words')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="blur" class="form-label">Seuil de netteté</label>
                        <input type="number" step="1" class="form-control" id="blur"
                               name="thresholds[blur]" min="1" max="5000"
                               value="{{ old('thresholds.blur', $settings->threshold('blur', 100)) }}"
                               aria-describedby="blur-hint">
                        <div class="ag-hint mt-1" id="blur-hint">
                            Variance du Laplacien. En dessous de cette valeur, l’image est signalée
                            comme <em>potentiellement</em> floue. Augmenter le seuil rend la
                            détection plus stricte.
                        </div>
                        @error('thresholds.blur')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="min_image_width" class="form-label">Largeur minimale</label>
                            <input type="number" class="form-control" id="min_image_width"
                                   name="thresholds[min_image_width]" min="0" max="10000"
                                   value="{{ old('thresholds.min_image_width', $settings->threshold('min_image_width', 600)) }}">
                        </div>
                        <div class="col-6">
                            <label for="min_image_height" class="form-label">Hauteur minimale</label>
                            <input type="number" class="form-control" id="min_image_height"
                                   name="thresholds[min_image_height]" min="0" max="10000"
                                   value="{{ old('thresholds.min_image_height', $settings->threshold('min_image_height', 400)) }}">
                        </div>
                    </div>

                    <div>
                        <label for="relevance" class="form-label">Seuil de cohérence des images</label>
                        <input type="number" step="0.05" class="form-control" id="relevance"
                               name="thresholds[relevance]" min="0" max="1"
                               value="{{ old('thresholds.relevance', $settings->threshold('relevance', 0.35)) }}"
                               aria-describedby="relevance-hint">
                        <div class="ag-hint mt-1" id="relevance-hint">
                            Entre 0 et 1. Il s’agit d’une heuristique : elle signale un doute à
                            vérifier, jamais une certitude, et ne détermine pas si une image a été
                            générée par une IA.
                        </div>
                        @error('thresholds.relevance')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="ag-card mb-3">
                <div class="ag-card__header">
                    <h2 class="ag-card__title">Analyse de pertinence</h2>
                </div>
                <div class="ag-card__body small">
                    <p class="mb-2">
                        Fournisseur actif :
                        <span class="ag-badge ag-badge--{{ $relevanceAvailable ? 'success' : 'muted' }}">
                            {{ $relevanceDriver ?: 'désactivé' }}
                        </span>
                    </p>
                    <p class="ag-hint mb-0">
                        Le pilote <span class="ag-mono">heuristic</span> compare localement le
                        vocabulaire de l’image (nom de fichier, texte alternatif, légende) à celui
                        de l’article. Un fournisseur de vision distant peut être branché via
                        <span class="ag-mono">AG_RELEVANCE_DRIVER</span> sans modifier le moteur d’audit.
                    </p>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">Enregistrer les paramètres</button>
        </div>
    </div>
</form>
@endsection
