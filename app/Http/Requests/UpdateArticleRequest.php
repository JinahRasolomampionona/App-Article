<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends FormRequest
{
    /**
     * Seul le détenteur du verrou peut enregistrer. La réponse de la policy
     * est renvoyée telle quelle : 409 et message lisible (« Cet article est
     * actuellement traité par Daniella. ») plutôt qu'un 403 générique.
     */
    public function authorize(): Response|bool
    {
        if ($this->user() === null) {
            return false;
        }

        return Gate::forUser($this->user())->inspect('update', $this->route('article'));
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
