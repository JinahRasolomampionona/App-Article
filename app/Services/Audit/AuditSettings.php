<?php

namespace App\Services\Audit;

use App\Models\User;

/**
 * Réglages d'audit effectifs : préférences de l'utilisateur superposées aux
 * valeurs par défaut de `config/articleguard.php`.
 *
 * Aucun seuil n'est codé en dur dans les règles.
 */
class AuditSettings
{
    /** Seuils exprimés en nombre décimal. */
    private const FLOAT_THRESHOLDS = ['blur', 'relevance'];

    /** Seuils exprimés en nombre entier. */
    private const INT_THRESHOLDS = ['title_max_words', 'min_image_width', 'min_image_height'];

    /** @var array<string, float|int> */
    protected array $thresholds;

    /**
     * @param  array<string, bool>  $rules
     * @param  array<string, float|int|string>  $thresholds
     */
    public function __construct(
        protected array $rules,
        array $thresholds,
    ) {
        $this->thresholds = $this->normalize($thresholds);
    }

    /**
     * Fixe le type de chaque seuil.
     *
     * Un seuil décimal rond (150.0) revient d'une colonne JSON sous forme
     * d'entier : sans cette normalisation, le type dépendrait de la valeur
     * saisie par l'utilisateur.
     *
     * @param  array<string, float|int|string>  $thresholds
     * @return array<string, float|int>
     */
    protected function normalize(array $thresholds): array
    {
        foreach (self::FLOAT_THRESHOLDS as $key) {
            if (array_key_exists($key, $thresholds)) {
                $thresholds[$key] = (float) $thresholds[$key];
            }
        }

        foreach (self::INT_THRESHOLDS as $key) {
            if (array_key_exists($key, $thresholds)) {
                $thresholds[$key] = (int) $thresholds[$key];
            }
        }

        return $thresholds;
    }

    public static function defaults(): self
    {
        return new self(
            array_map('boolval', (array) config('articleguard.rules')),
            (array) config('articleguard.thresholds'),
        );
    }

    public static function forUser(?User $user): self
    {
        $defaults = self::defaults();

        if ($user === null) {
            return $defaults;
        }

        $stored = $user->relationLoaded('settings') ? $user->settings : $user->settings()->first();

        if ($stored === null) {
            return $defaults;
        }

        return new self(
            array_merge($defaults->rules, array_map('boolval', (array) ($stored->rules ?? []))),
            array_merge($defaults->thresholds, (array) ($stored->thresholds ?? [])),
        );
    }

    public function ruleEnabled(string $key): bool
    {
        return (bool) ($this->rules[$key] ?? false);
    }

    public function threshold(string $key, float|int $fallback = 0): float|int
    {
        return $this->thresholds[$key] ?? $fallback;
    }

    /** @return array<string, bool> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return array<string, float|int> */
    public function thresholds(): array
    {
        return $this->thresholds;
    }

    /**
     * Les règles qui déclenchent des appels réseau (téléchargement d'images).
     *
     * @return array<int, string>
     */
    public static function networkRules(): array
    {
        return ['image_blur', 'image_relevance'];
    }
}
