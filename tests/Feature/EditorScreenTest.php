<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rendu des écrans : l'éditeur expose les deux panneaux de détails d'image, et
 * la sidebar ses sections repliables.
 */
class EditorScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_editeur_expose_les_details_du_fichier_joint_et_de_l_image(): void
    {
        $user = User::factory()->create();
        $site = WordpressSite::factory()->for($user)->create(['url' => 'https://example.com']);
        $article = WordpressArticle::factory()->for($site, 'site')->create([
            'content' => '<p>Texte</p><img src="https://example.com/a.jpg" alt="">',
        ]);

        $response = $this->actingAs($user)->get(route('articles.edit', $article));

        $response->assertOk()
            // Détails du fichier joint (médiathèque).
            ->assertSee('Détails du fichier joint', false)
            ->assertSee('Légende de l’image', false)
            ->assertSee('URL de fichier', false)
            // Détails de l'image du contenu.
            ->assertSee('Détails de l’image', false)
            ->assertSee('Réglages de l’affichage pour l’image', false)
            ->assertSee('Alignement', false)
            ->assertSee('Conserver les proportions', false);
    }

    public function test_les_sections_de_la_sidebar_sont_repliables(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('data-nav-toggle', false)
            ->assertSee('aria-controls="ag-nav-articleguard-desktop"', false)
            ->assertSee('aria-controls="ag-nav-configuration-offcanvas"', false);
    }

    public function test_la_sidebar_peut_etre_reduite_en_barre_d_icones(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('data-nav-rail', false)
            ->assertSee('aria-controls="ag-sidebar"', false)
            // Les libellés sont isolés pour pouvoir être masqués, et repris en
            // infobulle une fois la sidebar réduite.
            ->assertSee('class="ag-nav__label"', false)
            ->assertSee('data-label="Sites WordPress"', false)
            // L'état est appliqué avant le premier rendu.
            ->assertSee("localStorage.getItem('ag.nav.rail')", false);
    }
}
