<?php

namespace App\Http\Controllers;

use App\Models\UserSetting;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use App\Services\Audit\Relevance\ImageRelevanceAnalyzerInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(
        protected AuditService $audit,
        protected ImageRelevanceAnalyzerInterface $relevance,
    ) {}

    public function edit(Request $request): View
    {
        return view('settings.index', [
            'catalog' => $this->audit->catalog(),
            'settings' => AuditSettings::forUser($request->user()),
            'relevanceAvailable' => $this->relevance->isAvailable(),
            'relevanceDriver' => config('articleguard.relevance.driver'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $keys = array_column($this->audit->catalog(), 'key');

        $validated = $request->validate([
            'rules' => ['nullable', 'array'],
            'thresholds.title_max_words' => ['required', 'integer', 'min:3', 'max:60'],
            'thresholds.blur' => ['required', 'numeric', 'min:1', 'max:5000'],
            'thresholds.min_image_width' => ['required', 'integer', 'min:0', 'max:10000'],
            'thresholds.min_image_height' => ['required', 'integer', 'min:0', 'max:10000'],
            'thresholds.relevance' => ['required', 'numeric', 'min:0', 'max:1'],
        ], [], [
            'thresholds.title_max_words' => 'nombre maximal de mots du H1',
            'thresholds.blur' => 'seuil de netteté',
            'thresholds.min_image_width' => 'largeur minimale',
            'thresholds.min_image_height' => 'hauteur minimale',
            'thresholds.relevance' => 'seuil de cohérence',
        ]);

        // Une case non cochée n'est pas transmise : on reconstruit l'état
        // complet à partir du catalogue plutôt que de l'entrée utilisateur.
        $submitted = (array) ($validated['rules'] ?? []);
        $rules = [];

        foreach ($keys as $key) {
            $rules[$key] = (bool) ($submitted[$key] ?? false);
        }

        UserSetting::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'rules' => $rules,
                'thresholds' => [
                    'title_max_words' => (int) $validated['thresholds']['title_max_words'],
                    'blur' => (float) $validated['thresholds']['blur'],
                    'min_image_width' => (int) $validated['thresholds']['min_image_width'],
                    'min_image_height' => (int) $validated['thresholds']['min_image_height'],
                    'relevance' => (float) $validated['thresholds']['relevance'],
                ],
            ]
        );

        return redirect()
            ->route('settings.edit')
            ->with('status', 'Paramètres enregistrés. Ils s’appliqueront au prochain audit.');
    }
}
