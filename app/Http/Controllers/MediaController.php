<?php

namespace App\Http\Controllers;

use App\Models\WordpressSite;
use App\Services\WordPress\WordPressApiException;
use App\Services\WordPress\WordPressMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accès à la médiathèque WordPress depuis l'éditeur (sélection et upload).
 */
class MediaController extends Controller
{
    public function __construct(
        protected WordPressMediaService $media,
    ) {}

    public function index(Request $request, WordpressSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        try {
            $library = $this->media->library(
                $site,
                $validated['search'] ?? null,
                (int) ($validated['page'] ?? 1),
            );
        } catch (WordPressApiException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true] + $library);
    }

    /**
     * Détails d'un média : sert au panneau « Détails de l'image » de l'éditeur,
     * qui a besoin des tailles disponibles pour une image déjà dans le contenu.
     */
    public function show(WordpressSite $site, int $media): JsonResponse
    {
        $this->authorize('view', $site);

        try {
            $found = $this->media->find($site, $media);
        } catch (WordPressApiException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        if (! $found) {
            return response()->json([
                'ok' => false,
                'message' => 'Ce média est introuvable dans la médiathèque WordPress.',
            ], 404);
        }

        return response()->json(['ok' => true, 'media' => $found]);
    }

    /**
     * Enregistre les métadonnées du média sur WordPress (texte alternatif,
     * titre, légende, description).
     */
    public function update(Request $request, WordpressSite $site, int $media): JsonResponse
    {
        $this->authorize('manageMedia', $site);

        $validated = $request->validate([
            'alt_text' => ['present', 'nullable', 'string', 'max:512'],
            'title' => ['present', 'nullable', 'string', 'max:255'],
            'caption' => ['present', 'nullable', 'string', 'max:2000'],
            'description' => ['present', 'nullable', 'string', 'max:5000'],
        ], [], [
            'alt_text' => 'texte alternatif',
            'caption' => 'légende',
        ]);

        try {
            $updated = $this->media->updateDetails(
                $site,
                $media,
                array_map(fn ($value) => (string) $value, $validated),
            );
        } catch (WordPressApiException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Détails du fichier enregistrés sur WordPress.',
            'media' => $updated,
        ]);
    }

    public function store(Request $request, WordpressSite $site): JsonResponse
    {
        $this->authorize('manageMedia', $site);

        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:10240'],
        ], [], ['file' => 'image']);

        try {
            $media = $this->media->upload($site, $request->file('file'));
        } catch (WordPressApiException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Image ajoutée à la médiathèque WordPress.',
            'media' => $media,
        ]);
    }
}
