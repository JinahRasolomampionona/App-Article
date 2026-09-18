<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('article')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $article = $this->route('article');

        return [
            'title' => ['required', 'string', 'max:500'],
            // Le HTML brut est conservé tel quel : c'est WordPress qui
            // l'assainit selon les capacités du compte utilisé.
            'content' => ['nullable', 'string', 'max:2000000'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[\pL\pN._~-]+$/u'],
            'status' => ['nullable', Rule::in(['publish', 'draft', 'pending', 'private'])],
            'featured_media_id' => ['nullable', 'integer', 'min:0'],
            'categories' => ['nullable', 'array'],
            'categories.*' => [
                'integer',
                Rule::exists('wordpress_categories', 'id')
                    ->where('wordpress_site_id', $article?->wordpress_site_id),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge(['slug' => trim((string) $this->input('slug'), '/ ')]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'titre',
            'content' => 'contenu',
            'slug' => 'URL',
            'categories' => 'catégories',
            'featured_media_id' => 'image mise en avant',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'L’URL ne peut contenir que des lettres, chiffres, tirets et points.',
            'categories.*.exists' => 'Une des catégories sélectionnées n’appartient pas à ce site.',
        ];
    }
}
