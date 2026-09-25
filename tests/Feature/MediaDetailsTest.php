<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Panneau « Détails du fichier joint » de l'éditeur : lecture et écriture des
 * métadonnées d'un média dans la médiathèque WordPress.
 */
class MediaDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->site = WordpressSite::factory()->for($this->user)->create(['url' => 'https://example.com']);
    }

    public function test_les_details_d_un_media_exposent_les_champs_wordpress(): void
    {
        Http::fake([
            'example.com/wp-json/wp/v2/media/12*' => Http::response($this->media()),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('sites.media.show', [$this->site, 12]));

        $response->assertOk()
            ->assertJsonPath('media.alt', 'Bague en or')
            ->assertJsonPath('media.title', 'Bague ancienne')
            ->assertJsonPath('media.caption', 'Photographiée en atelier')
            ->assertJsonPath('media.description', 'Prise de vue studio')
            ->assertJsonPath('media.filename', 'bague.jpg')
            ->assertJsonPath('media.url', 'https://example.com/bague.jpg');
    }

    public function test_les_tailles_disponibles_sont_proposees_avec_la_taille_originale(): void
    {
        Http::fake([
            'example.com/wp-json/wp/v2/media/12*' => Http::response($this->media()),
        ]);

        $sizes = $this->actingAs($this->user)
            ->getJson(route('sites.media.show', [$this->site, 12]))
            ->json('media.sizes');

        $this->assertSame(['thumbnail', 'full'], array_column($sizes, 'name'));
        $this->assertSame([150, 1200], array_column($sizes, 'width'));
        $this->assertSame('Taille originale', $sizes[1]['label']);
    }

    public function test_un_media_absent_renvoie_une_erreur_lisible(): void
    {
        Http::fake([
            'example.com/wp-json/wp/v2/media/99*' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        ]);

        $this->actingAs($this->user)
            ->getJson(route('sites.media.show', [$this->site, 99]))
            ->assertNotFound()
            ->assertJsonPath('ok', false);
    }

    public function test_l_enregistrement_envoie_les_champs_a_wordpress(): void
    {
        Http::fake([
            'example.com/wp-json/wp/v2/media/12*' => Http::response($this->media([
                'alt_text' => 'Nouvelle description',
            ])),
        ]);

        $response = $this->actingAs($this->user)->putJson(
            route('sites.media.update', [$this->site, 12]),
            [
                'alt_text' => 'Nouvelle description',
                'title' => 'Bague ancienne',
                'caption' => 'Photographiée en atelier',
                'description' => 'Prise de vue studio',
            ],
        );

        $response->assertOk()->assertJsonPath('media.alt', 'Nouvelle description');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/wp/v2/media/12')
                && $request['alt_text'] === 'Nouvelle description'
                && $request['caption'] === 'Photographiée en atelier';
        });
    }

    public function test_un_site_sans_credentials_ne_peut_pas_modifier_un_media(): void
    {
        Http::fake();

        $site = WordpressSite::factory()->for($this->user)->readOnly()->create([
            'url' => 'https://lecture-seule.test',
        ]);

        $this->actingAs($this->user)
            ->putJson(route('sites.media.update', [$site, 12]), [
                'alt_text' => 'Texte',
                'title' => '',
                'caption' => '',
                'description' => '',
            ])
            ->assertStatus(502);

        Http::assertNothingSent();
    }

    /**
     * Espace partagé : la médiathèque est ouverte à tous les comptes actifs,
     * mais un compte désactivé perd l'accès immédiatement.
     */
    public function test_un_compte_desactive_n_accede_plus_a_la_mediatheque(): void
    {
        Http::fake();

        $this->user->forceFill(['is_active' => false])->save();

        $this->actingAs($this->user)
            ->getJson(route('sites.media.show', [$this->site, 12]))
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function media(array $overrides = []): array
    {
        return array_replace([
            'id' => 12,
            'source_url' => 'https://example.com/bague.jpg',
            'alt_text' => 'Bague en or',
            'mime_type' => 'image/jpeg',
            'title' => ['rendered' => 'Bague ancienne'],
            'caption' => ['rendered' => '<p>Photographiée en atelier</p>'],
            'description' => ['rendered' => '<p>Prise de vue studio</p>'],
            'media_details' => [
                'width' => 1200,
                'height' => 800,
                'file' => '2026/01/bague.jpg',
                'sizes' => [
                    'thumbnail' => [
                        'source_url' => 'https://example.com/bague-150x150.jpg',
                        'width' => 150,
                        'height' => 150,
                    ],
                ],
            ],
        ], $overrides);
    }
}
