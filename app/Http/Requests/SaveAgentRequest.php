<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Création / modification d'un compte par l'Admin.
 *
 * Le mot de passe est obligatoire à la création, facultatif ensuite (champ
 * vide = inchangé). Le rôle et l'activation ne sont acceptés que si l'Admin a
 * le droit de les changer pour ce compte — jamais pour lui-même.
 */
class SaveAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            ? $this->user()->can('update', $target)
            : $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required', 'string', 'email:filter', 'max:255',
                Rule::unique('users', 'email')->ignore($target?->id),
            ],
            'password' => [
                $target ? 'nullable' : 'required',
                'confirmed',
                Password::defaults()->min(8)->letters()->numbers(),
            ],
            'role' => ['sometimes', Rule::in([User::ROLE_ADMIN, User::ROLE_AGENT])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'email' => 'adresse e-mail',
            'password' => 'mot de passe',
            'role' => 'rôle',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Un compte existe déjà avec cette adresse e-mail.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ];
    }
}
