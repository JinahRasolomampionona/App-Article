<?php

namespace App\Http\Requests;

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

            $duplicate = $this->user()->sites()
                ->where('url', $this->normalizedUrl)
                ->when($this->route('site'), fn ($query, $site) => $query->whereKeyNot($site->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('url', 'Ce site est déjà connecté à votre compte.');
            }
        });
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
