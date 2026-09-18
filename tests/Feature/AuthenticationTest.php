<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_de_connexion_est_accessible(): void
    {
        $this->get('/login')->assertOk()->assertSee('ArticleGuard');
    }

    public function test_un_visiteur_peut_creer_un_compte(): void
    {
        $response = $this->post('/register', [
            'name' => 'Marie Dupont',
            'email' => 'marie@example.com',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::firstWhere('email', 'marie@example.com');

        $this->assertNotNull($user);
        // Le mot de passe ne doit jamais être stocké en clair.
        $this->assertNotSame('motdepasse1', $user->password);
        $this->assertTrue(password_verify('motdepasse1', $user->password));
    }

    public function test_l_inscription_refuse_un_email_deja_utilise(): void
    {
        User::factory()->create(['email' => 'marie@example.com']);

        $this->post('/register', [
            'name' => 'Marie',
            'email' => 'marie@example.com',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_l_inscription_refuse_un_mot_de_passe_trop_court(): void
    {
        $this->post('/register', [
            'name' => 'Marie',
            'email' => 'marie@example.com',
            'password' => 'court1',
            'password_confirmation' => 'court1',
        ])->assertSessionHasErrors('password');
    }

    public function test_un_utilisateur_peut_se_connecter(): void
    {
        $user = User::factory()->create(['password' => 'motdepasse1']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'motdepasse1',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_un_mot_de_passe_invalide_est_refuse(): void
    {
        $user = User::factory()->create(['password' => 'motdepasse1']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'mauvais',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_un_utilisateur_peut_se_deconnecter(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[DataProvider('protectedRoutes')]
    public function test_les_pages_metier_exigent_une_authentification(string $uri): void
    {
        $this->get($uri)->assertRedirect(route('login'));
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function protectedRoutes(): array
    {
        return [
            ['/dashboard'],
            ['/articles'],
            ['/audits'],
            ['/sites'],
            ['/settings'],
        ];
    }
}
