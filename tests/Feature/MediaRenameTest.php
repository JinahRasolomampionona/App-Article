<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WordpressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Renommage d'une image du contenu : WordPress ne renomme pas un fichier, une
 * copie est téléversée sous le nouveau nom avec les textes du média d'origine.
 */
class MediaRenameTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected WordpressSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->site = WordpressSite::factory()->for($this->user)->create(['url' => 'https://example.com']);
    }

    public function test_l_image_est_copiee_sous_le_nouveau_nom_avec_ses_textes(): void
    {
        Http::fake([
            'https://example.com/wp-json/wp/v2/media/12*' => Http::response([
                'id' => 12,
                'source_url' => 'https://example.com/wp-content/uploads/IMG_0042.jpg',
                'alt_text' => 'Bague en or',
                'caption' => ['raw' => 'Une bague'],
                'description' => ['raw' => ''],
            ]),
            'https://example.com/wp-content/uploads/IMG_0042.jpg' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://example.com/wp-json/wp/v2/media/40*' => Http::response($this->media(40, 'bague-or-18-carats.jpg', 'Bague en or')),
            'https://example.com/wp-json/wp/v2/media' => Http::response($this->media(40, 'bague-or-18-carats.jpg', ''), 201),
        ]);

        $this->actingAs($this->user)
            ->postJson(route('sites.media.rename', $this->site), [
                'src' => 'https://example.com/wp-content/uploads/IMG_0042-1024x768.jpg',
                'media_id' => 12,
                'name' => 'Bague or 18 carats',
            ])
            ->assertOk()
            ->assertJsonPath('media.id', 40)
            ->assertJsonPath('media.alt', 'Bague en or');

        // Fichier d'origine (pleine taille) téléversé sous le nom normalisé.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://example.com/wp-json/wp/v2/media'
            && str_contains($request->header('Content-Disposition')[0] ?? '', 'filename="bague-or-18-carats.jpg"'));

        // Les textes suivent la copie.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://example.com/wp-json/wp/v2/media/40')
            && $request['alt_text'] === 'Bague en or'
            && $request['title'] === 'Bague or 18 carats');
    }

    public function test_un_nom_sans_lettre_ni_chiffre_est_refuse(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->postJson(route('sites.media.rename', $this->site), [
                'src' => 'https://example.com/wp-content/uploads/a.jpg',
                'name' => '---',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_un_compte_sans_acces_au_site_ne_peut_pas_renommer(): void
    {
        Http::fake();

        $agent = User::factory()->create();

        $this->actingAs($agent)
            ->postJson(route('sites.media.rename', $this->site), [
                'src' => 'https://example.com/wp-content/uploads/a.jpg',
                'name' => 'nouveau-nom',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    protected function media(int $id, string $file, string $alt): array
    {
        return [
            'id' => $id,
            'source_url' => 'https://example.com/wp-content/uploads/'.$file,
            'alt_text' => $alt,
            'mime_type' => 'image/jpeg',
            'media_details' => ['width' => 64, 'height' => 48, 'file' => $file, 'sizes' => []],
        ];
    }

    protected function jpeg(): string
    {
        $image = imagecreatetruecolor(64, 48);
        ob_start();
        imagejpeg($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
