<?php

namespace Tests\Unit;

use App\Models\WordpressSite;
use Tests\TestCase;

/**
 * Normalisation et contrôle de format des Application Passwords WordPress.
 *
 * WordPress affiche le secret par groupes de quatre caractères et un
 * copier-coller depuis l'admin ramène régulièrement des espaces insécables :
 * transmis tels quels dans l'en-tête `Authorization`, ils font échouer
 * l'authentification.
 */
class ApplicationPasswordTest extends TestCase
{
    public function test_les_espaces_decoratifs_sont_retires(): void
    {
        $this->assertSame(
            'abcd1234abcd1234abcd1234',
            WordpressSite::normalizeApplicationPassword('abcd 1234 abcd 1234 abcd 1234')
        );
    }

    public function test_les_espaces_insecables_et_sauts_de_ligne_sont_retires(): void
    {
        $this->assertSame(
            'abcd1234abcd1234abcd1234',
            WordpressSite::normalizeApplicationPassword("abcd\u{00A0}1234\tabcd 1234\nabcd 1234 ")
        );
    }

    public function test_une_valeur_vide_devient_null(): void
    {
        $this->assertNull(WordpressSite::normalizeApplicationPassword(null));
        $this->assertNull(WordpressSite::normalizeApplicationPassword('   '));
    }

    public function test_le_format_wordpress_est_reconnu(): void
    {
        $site = new WordpressSite(['application_password' => 'abcd1234abcd1234abcd1234']);

        $this->assertTrue($site->applicationPasswordLooksLikeWordPress());
    }

    /**
     * Cas réel le plus fréquent : l'utilisateur saisit le mot de passe de son
     * compte wp-admin, que l'API REST n'accepte jamais.
     */
    public function test_un_mot_de_passe_de_compte_n_est_pas_reconnu(): void
    {
        $site = new WordpressSite(['application_password' => 'MonMotDePasse!2024']);

        $this->assertFalse($site->applicationPasswordLooksLikeWordPress());

        $site->application_password = null;

        $this->assertFalse($site->applicationPasswordLooksLikeWordPress());
    }
}
