<?php

namespace App\Http\Requests;

use App\Models\WordpressSite;
use App\Support\UnsafeUrlException;
use App\Support\UrlGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSiteRequest extends FormRequest
{
    protected ?string $normalizedUrl = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:255'],
            'wp_username' => ['nullable', 'string', 'max:120'],
            'application_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * L'URL est normalisée puis contrôlée (schéma, port, SSRF) avant toute
     * autre validation métier.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('url')) {
                return;
            }

            try {
                $this->normalizedUrl = app(UrlGuard::class)->normalize((string) $this->input('url'));
                app(UrlGuard::class)->assertSafe($this->normalizedUrl);
            } catch (UnsafeUrlException $e) {
                $validator->errors()->add('url', $e->getMessage());

                return;
            }

            $this->validateUrlOwnership($validator);
        });
    }

    /**
     * Création : un site déjà connecté par un autre compte est rejoint (voir
     * existingSite()) ; seul un doublon pour le même compte est refusé.
     */
    protected function validateUrlOwnership(Validator $validator): void
    {
        $existing = $this->existingSite();

        if ($existing !== null && $existing->connections()->where('user_id', $this->user()->id)->exists()) {
            $validator->errors()->add('url', 'Vous avez déjà connecté ce site.');
        }
    }

    /** Site déjà présent à cette adresse, connecté par un autre compte. */
    public function existingSite(): ?WordpressSite
    {
        if ($this->normalizedUrl === null) {
            return null;
        }

        return WordpressSite::query()
            ->where('url', $this->normalizedUrl)
            ->when($this->route('site'), fn ($query, $site) => $query->whereKeyNot($site->id))
            ->first();
    }

    public function normalizedUrl(): string
    {
        return (string) $this->normalizedUrl;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom du site',
            'url' => 'adresse du site',
            'wp_username' => 'identifiant WordPress',
            'application_password' => 'Application Password',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Indiquez l’adresse du site WordPress, par exemple https://exemple.com.',
        ];
    }
}
