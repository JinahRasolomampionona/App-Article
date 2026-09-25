<?php

namespace App\Http\Requests;

use App\Models\WordpressSite;
use Illuminate\Validation\Validator;

/**
 * Mise à jour d'un site connecté.
 *
 * L'Application Password laissée vide signifie « conserver la valeur
 * existante » : l'interface ne la réaffiche jamais en clair, l'utilisateur ne
 * peut donc pas la ressaisir à l'identique.
 *
 * Les identifiants sont ceux de l'utilisateur. Le nom et l'adresse, partagés
 * avec les autres comptes qui ont connecté le site, ne se modifient que s'il
 * est seul à l'avoir connecté (l'Admin peut toujours renommer).
 */
class UpdateSiteRequest extends StoreSiteRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('site')) ?? false;
    }

    public function canRenameSite(): bool
    {
        return $this->user()->isAdmin() || $this->isSoleConnection();
    }

    public function canChangeUrl(): bool
    {
        return $this->isSoleConnection();
    }

    protected function isSoleConnection(): bool
    {
        /** @var WordpressSite $site */
        $site = $this->route('site');

        return ! $site->connections()->where('user_id', '!=', $this->user()->id)->exists();
    }

    /**
     * Modification : l'adresse ne peut pas devenir celle d'un autre site, et
     * ne change pas si d'autres comptes partagent ce site.
     */
    protected function validateUrlOwnership(Validator $validator): void
    {
        /** @var WordpressSite $site */
        $site = $this->route('site');

        if ($this->normalizedUrl() === $site->url) {
            return;
        }

        if (! $this->canChangeUrl()) {
            $validator->errors()->add('url', 'D’autres comptes ont connecté ce site : son adresse ne peut pas être modifiée.');

            return;
        }

        if ($this->existingSite() !== null) {
            $validator->errors()->add('url', 'Un autre site est déjà connecté à cette adresse. Connectez-le depuis « Connecter un site ».');
        }
    }
}
