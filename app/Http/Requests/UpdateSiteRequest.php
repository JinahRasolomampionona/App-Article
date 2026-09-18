<?php

namespace App\Http\Requests;

/**
 * Mise à jour d'un site connecté.
 *
 * L'Application Password laissée vide signifie « conserver la valeur
 * existante » : l'interface ne la réaffiche jamais en clair, l'utilisateur ne
 * peut donc pas la ressaisir à l'identique.
 */
class UpdateSiteRequest extends StoreSiteRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('site')) ?? false;
    }
}
