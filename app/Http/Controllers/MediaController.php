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

    public function store(Request $request, WordpressSite $site): JsonResponse
    {
        $this->authorize('update', $site);

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
