<?php

namespace Tests\Feature;

use App\Models\WordpressSite;
use App\Services\WordPress\WordPressApiException;
use App\Services\WordPress\WordPressApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Toutes les requêtes sortantes sont simulées : aucun test ne contacte un vrai
 * site WordPress.
 */
class WordPressApiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WordpressSite $site;

    protected WordPressApiService $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = WordpressSite::factory()->create(['url' => 'https://example.com']);
        $this->api = app(WordPressApiService::class);
    }

    public function test_le_test_de_connexion_valide_l_authentification(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Bijouteries', 'namespaces' => ['wp/v2'], 'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/wp-admin/authorize-application.php']]]]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['id' => 1, 'name' => 'Éditeur']),
        ]);

        $result = $this->api->testConnection($this->site);

        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['authenticated']);
        $this->assertSame('Bijouteries', $result['name']);
    }

    public function test_une_authentification_refusee_donne_un_message_comprehensible(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Site', 'namespaces' => ['wp/v2'], 'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/wp-admin/authorize-application.php']]]]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['message' => 'Invalid credentials'], 401),
        ]);

        try {
            $this->api->testConnection($this->site);
            $this->fail('Une WordPressApiException était attendue.');
        } catch (WordPressApiException $e) {
            $this->assertSame('unauthorized', $e->reason);
            $this->assertStringContainsString('Application Password', $e->getMessage());
            // Le détail technique ne remonte pas jusqu'à l'utilisateur.
            $this->assertStringNotContainsString('Invalid credentials', $e->getMessage());
        }
    }

    public function test_un_site_injoignable_donne_un_message_comprehensible(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        try {
            $this->api->testConnection($this->site);
            $this->fail('Une WordPressApiException était attendue.');
        } catch (WordPressApiException $e) {
            $this->assertSame('unreachable', $e->reason);
            $this->assertStringContainsString('Impossible de contacter le site WordPress', $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    public function test_les_categories_sont_recuperees_sur_plusieurs_pages(): void
    {
        Http::fakeSequence()
            ->push([['id' => 1, 'name' => 'Bagues', 'slug' => 'bagues', 'parent' => 0, 'count' => 4]], 200, ['X-WP-TotalPages' => 2, 'X-WP-Total' => 2])
            ->push([['id' => 2, 'name' => 'Colliers', 'slug' => 'colliers', 'parent' => 0, 'count' => 6]], 200, ['X-WP-TotalPages' => 2, 'X-WP-Total' => 2]);

        $categories = $this->api->fetchAllCategories($this->site);

        $this->assertCount(2, $categories);
        $this->assertSame('Colliers', $categories[1]['name']);
    }

    public function test_la_pagination_des_articles_lit_les_entetes_wordpress(): void
    {
        Http::fake([
            '*' => Http::response(
                [['id' => 10, 'title' => ['raw' => 'Article'], 'content' => ['raw' => '<p>Texte</p>']]],
                200,
                ['X-WP-Total' => 57, 'X-WP-TotalPages' => 3],
            ),
        ]);

        $result = $this->api->fetchPostsPage($this->site, 2);

        $this->assertSame(57, $result->total);
        $this->assertSame(3, $result->totalPages);
        $this->assertSame(2, $result->page);
        $this->assertTrue($result->hasMorePages());
    }

    public function test_tous_les_articles_sont_parcourus_page_par_page(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ((int) $query['page']) {
                1 => Http::response([['id' => 1], ['id' => 2]], 200, ['X-WP-Total' => 3, 'X-WP-TotalPages' => 2]),
                default => Http::response([['id' => 3]], 200, ['X-WP-Total' => 3, 'X-WP-TotalPages' => 2]),
            };
        });

        $seen = [];
        $count = $this->api->eachPost($this->site, function (array $posts) use (&$seen) {
            foreach ($posts as $post) {
                $seen[] = $post['id'];
            }
        });

        $this->assertSame(3, $count);
        $this->assertSame([1, 2, 3], $seen);
    }

    /**
     * Connexion lente : une page trop lourde est redemandée en plus petit,
     * sans perdre ni dupliquer d'article, au lieu d'arrêter la synchronisation.
     */
    public function test_une_page_trop_lente_est_redemandee_en_plus_petit(): void
    {
        config(['articleguard.http.posts_per_page' => 20, 'articleguard.http.retry_times' => 1]);
        $timedOut = false;

        Http::fake(function (Request $request) use (&$timedOut) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $perPage = (int) $query['per_page'];
            $page = (int) $query['page'];

            // Première page OK, la deuxième expire en taille 20.
            if ($page === 2 && $perPage === 20) {
                $timedOut = true;
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            $ids = range(($page - 1) * $perPage + 1, min(50, $page * $perPage));

            return Http::response(
                array_map(fn ($id) => ['id' => $id], $ids),
                200,
                ['X-WP-Total' => 50, 'X-WP-TotalPages' => (int) ceil(50 / $perPage)],
            );
        });

        $seen = [];
        $count = $this->api->eachPost($this->site, function (array $posts) use (&$seen) {
            foreach ($posts as $post) {
                $seen[] = $post['id'];
            }
        });

        $this->assertTrue($timedOut);
        $this->assertSame(50, $count);
        $this->assertSame(range(1, 50), $seen);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'per_page=10'));
    }

    public function test_les_reponses_sont_demandees_compressees(): void
    {
        Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 1])]);

        $this->api->fetchPostsPage($this->site);

        Http::assertSent(fn (Request $request) => str_contains($request->header('Accept-Encoding')[0] ?? '', 'gzip'));
    }

    public function test_le_contexte_edit_est_demande_quand_des_credentials_existent(): void
    {
        Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 1])]);

        $this->api->fetchPostsPage($this->site);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'context=edit')
            && str_contains($request->url(), 'status=any'));
    }

    public function test_sans_credentials_le_contexte_edit_n_est_pas_demande(): void
    {
        $site = WordpressSite::factory()->readOnly()->create(['url' => 'https://autre.example.com']);

        Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 1])]);

        $this->api->fetchPostsPage($site);

        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'context=edit'));
    }

    /**
     * Un compte authentifié mais sans capacité d'édition (rôle « abonné ») :
     * WordPress rejette `status=any` et `context=edit`. La synchronisation doit
     * se rabattre sur la lecture publique au lieu d'échouer entièrement.
     */
    public function test_un_compte_sans_droit_d_edition_bascule_en_lecture_seule(): void
    {
        Http::fakeSequence()
            ->push([
                'code' => 'rest_invalid_param',
                'message' => 'Paramètre(s) invalide(s) : « status »',
                'data' => ['status' => 400, 'details' => ['status' => ['code' => 'rest_forbidden_status']]],
            ], 400)
            ->push([['id' => 7, 'title' => ['rendered' => 'Publié']]], 200, ['X-WP-Total' => 1, 'X-WP-TotalPages' => 1]);

        $result = $this->api->fetchPostsPage($this->site);

        $this->assertCount(1, $result->items);
        $this->assertSame(7, $result->items[0]['id']);

        // Le second appel repart sans les paramètres refusés.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'context=edit')
            && ! str_contains($request->url(), 'status=any'));

        // Le constat est mémorisé : les pages suivantes n'essaient plus.
        $this->assertFalse($this->site->fresh()->wp_can_edit);
        $this->assertTrue($this->site->fresh()->isReadOnlyAccount());
    }

    /**
     * Compte « Auteur » dont un plugin restreint la liste en mode édition à
     * ses propres articles : WordPress répond 200 avec une liste vide. La
     * synchronisation doit récupérer les articles publiés plutôt que rien.
     */
    public function test_une_liste_vide_en_mode_edition_bascule_sur_les_articles_publies(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'context=edit')) {
                return Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 0]);
            }

            return Http::response([
                ['id' => 7, 'title' => ['rendered' => 'Publié A']],
                ['id' => 8, 'title' => ['rendered' => 'Publié B']],
            ], 200, ['X-WP-Total' => 2, 'X-WP-TotalPages' => 1]);
        });

        $ids = [];
        $this->api->eachPost($this->site, function (array $posts) use (&$ids) {
            $ids = [...$ids, ...array_column($posts, 'id')];
        });

        $this->assertSame([7, 8], $ids);
        // Le compte garde ses droits : seule la liste est lue en mode public.
        $this->assertNull($this->site->fresh()->wp_can_edit);
    }

    public function test_une_liste_complete_en_mode_edition_ne_bascule_pas(): void
    {
        Http::fake(fn () => Http::response([['id' => 7]], 200, ['X-WP-Total' => 1, 'X-WP-TotalPages' => 1]));

        $ids = [];
        $this->api->eachPost($this->site, function (array $posts) use (&$ids) {
            $ids = [...$ids, ...array_column($posts, 'id')];
        });

        $this->assertSame([7], $ids);
        // Une page complète suffit à conclure : aucune requête de contrôle.
        Http::assertSentCount(1);
    }

    /**
     * Cas réel (compte « Auteur ») : WordPress annonce tous les articles dans
     * `X-WP-Total` mais retire de la page ceux que le compte ne peut pas
     * modifier. La page revient vide ou incomplète, sans erreur.
     */
    public function test_une_page_edition_amputee_malgre_le_total_bascule_sur_les_articles_publies(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'context=edit')) {
                return Http::response([['id' => 7, 'title' => ['raw' => 'Le mien']]], 200, ['X-WP-Total' => 3, 'X-WP-TotalPages' => 1]);
            }

            return Http::response([
                ['id' => 7, 'title' => ['rendered' => 'Le mien']],
                ['id' => 8, 'title' => ['rendered' => 'Autre A']],
                ['id' => 9, 'title' => ['rendered' => 'Autre B']],
            ], 200, ['X-WP-Total' => 3, 'X-WP-TotalPages' => 1]);
        });

        $ids = [];
        $this->api->eachPost($this->site, function (array $posts) use (&$ids) {
            $ids = [...$ids, ...array_column($posts, 'id')];
        });

        $this->assertSame([7, 8, 9], $ids);
        $this->assertNull($this->site->fresh()->wp_can_edit);
    }

    public function test_la_liste_en_mode_edition_ne_demande_que_le_html_brut(): void
    {
        Http::fake(fn () => Http::response([['id' => 7]], 200, ['X-WP-Total' => 1, 'X-WP-TotalPages' => 1]));

        $this->api->fetchPostsPage($this->site);

        // Ni `rendered` ni extrait calculé : ce sont les champs les plus coûteux.
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'content.raw')
            && ! str_contains(urldecode($request->url()), 'excerpt,'));
    }

    public function test_un_site_marque_en_lecture_seule_ne_redemande_pas_le_contexte_edit(): void
    {
        $this->site->forceFill(['wp_can_edit' => false])->save();

        Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => 0, 'X-WP-TotalPages' => 1])]);

        $this->api->fetchPostsPage($this->site->fresh());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'context=edit'));
    }

    public function test_une_erreur_400_sans_rapport_avec_les_droits_n_est_pas_rejouee(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 'rest_invalid_param',
            'message' => 'Paramètre(s) invalide(s) : « per_page »',
            'data' => ['status' => 400, 'details' => ['per_page' => ['code' => 'rest_invalid_param']]],
        ], 400)]);

        $this->expectException(WordPressApiException::class);

        try {
            $this->api->fetchPostsPage($this->site);
        } finally {
            Http::assertSentCount(1);
            $this->assertNull($this->site->fresh()->wp_can_edit);
        }
    }

    public function test_le_test_de_connexion_releve_les_capacites_du_compte(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Site', 'namespaces' => ['wp/v2']]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 5,
                'name' => 'article',
                'roles' => ['subscriber'],
                'capabilities' => ['read' => true, 'subscriber' => true],
            ]),
        ]);

        $result = $this->api->testConnection($this->site);

        $this->assertTrue($result['authenticated']);
        $this->assertFalse($result['can_edit']);
        $this->assertSame('subscriber', $result['role']);
    }

    public function test_un_compte_editeur_est_reconnu_comme_pouvant_modifier(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Site', 'namespaces' => ['wp/v2']]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 2,
                'name' => 'Éditeur',
                'roles' => ['editor'],
                'capabilities' => ['read' => true, 'edit_posts' => true],
            ]),
        ]);

        $result = $this->api->testConnection($this->site);

        $this->assertTrue($result['can_edit']);
        $this->assertSame('editor', $result['role']);
    }

    public function test_un_site_qui_n_expose_pas_ses_capacites_ne_conclut_rien(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response(['name' => 'Site', 'namespaces' => ['wp/v2']]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['id' => 2, 'name' => 'Éditeur']),
        ]);

        $result = $this->api->testConnection($this->site);

        $this->assertNull($result['can_edit']);
    }

    public function test_un_media_est_recupere(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 12,
            'source_url' => 'https://example.com/photo.jpg',
            'alt_text' => 'Photo',
        ])]);

        $media = $this->api->fetchMedia($this->site, 12);

        $this->assertSame('https://example.com/photo.jpg', $media['source_url']);
    }

    public function test_un_media_absent_renvoie_null_sans_lever_d_exception(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not found'], 404)]);

        $this->assertNull($this->api->fetchMedia($this->site, 999));
    }

    public function test_la_mise_a_jour_envoie_uniquement_le_corps_fourni(): void
    {
        Http::fake(['*' => Http::response(['id' => 10, 'title' => ['raw' => 'Nouveau titre']])]);

        $this->api->updatePost($this->site, 10, ['title' => 'Nouveau titre']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/wp/v2/posts/10')
                && $request->data() === ['title' => 'Nouveau titre'];
        });
    }

    public function test_la_mise_a_jour_sans_credentials_est_refusee_avant_tout_appel(): void
    {
        $site = WordpressSite::factory()->readOnly()->create(['url' => 'https://autre.example.com']);
        Http::fake();

        $this->expectException(WordPressApiException::class);

        try {
            $this->api->updatePost($site, 10, ['title' => 'X']);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('httpErrors')]
    public function test_les_erreurs_http_sont_traduites(int $status, string $reason, string $fragment): void
    {
        Http::fake(['*' => Http::response(['message' => 'détail technique'], $status)]);

        try {
            $this->api->fetchPost($this->site, 1);
            $this->fail('Une WordPressApiException était attendue.');
        } catch (WordPressApiException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: string}>
     */
    public static function httpErrors(): array
    {
        return [
            [400, 'invalid_data', 'refusé les données'],
            [401, 'unauthorized', 'Application Password'],
            [403, 'forbidden', 'Accès refusé'],
            [404, 'not_found', 'introuvable'],
            [429, 'rate_limited', 'limite temporairement'],
            [500, 'server_error', 'erreur serveur'],
        ];
    }

    /**
     * WordPress renvoie `rest_not_logged_in` lorsqu'il n'a vu passer aucune
     * authentification : le message doit orienter vers la création d'une
     * Application Password et la transmission de l'en-tête Authorization,
     * jamais vers une simple erreur de saisie.
     */
    public function test_un_401_rest_not_logged_in_est_explique(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response([
                'name' => 'Site',
                'namespaces' => ['wp/v2'],
                'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/wp-admin/authorize-application.php']]],
            ]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response([
                'code' => 'rest_not_logged_in',
                'message' => 'Vous n’êtes actuellement pas connecté.',
            ], 401),
        ]);

        try {
            $this->api->testConnection($this->site);
            $this->fail('Une WordPressApiException était attendue.');
        } catch (WordPressApiException $e) {
            $this->assertSame('unauthorized', $e->reason);
            $this->assertSame('rest_not_logged_in', $e->wpCode());
            $this->assertStringContainsString('Application Password', $e->getMessage());
            $this->assertStringContainsString('Authorization', $e->getMessage());
        }
    }

    public function test_un_401_incorrect_password_indique_que_le_mot_de_passe_du_compte_est_refuse(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response([
                'name' => 'Site',
                'namespaces' => ['wp/v2'],
                'authentication' => ['application-passwords' => []],
            ]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response([
                'code' => 'incorrect_password',
                'message' => 'The provided password is an invalid application password.',
            ], 401),
        ]);

        try {
            $this->api->testConnection($this->site);
            $this->fail('Une WordPressApiException était attendue.');
        } catch (WordPressApiException $e) {
            $this->assertSame('incorrect_password', $e->wpCode());
            $this->assertStringContainsString('wp-admin', $e->getMessage());
            $this->assertStringNotContainsString('invalid application password', $e->getMessage());
        }
    }

    /**
     * Un site qui n'annonce pas `application-passwords` ne pourra jamais
     * authentifier la requête : le message ne doit pas envoyer l'utilisateur
     * vérifier ses identifiants pour rien.
     */
    public function test_un_site_sans_application_passwords_est_signale(): void
    {
        Http::fake([
            'example.com/wp-json?*' => Http::response([
                'name' => 'Site',
                'namespaces' => ['wp/v2'],
                'authentication' => [],
            ]),
            'example.com/wp-json/wp/v2/users/me*' => Http::response(['code' => 'rest_not_logged_in'], 401),
        ]);

        try {
            $this->api->testConnection($this->site);
            $this->fail('Une WordPressApiException était attendue.');
        } catch (WordPressApiException $e) {
            $this->assertSame('application_passwords_disabled', $e->wpCode());
            $this->assertStringContainsString('désactivées', $e->getMessage());
        }
    }

    public function test_le_support_des_application_passwords_est_lu_dans_la_racine_rest(): void
    {
        $this->assertTrue($this->api->supportsApplicationPasswords([
            'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/a.php']]],
        ]));
        $this->assertFalse($this->api->supportsApplicationPasswords(['authentication' => []]));
        $this->assertFalse($this->api->supportsApplicationPasswords([]));

        $this->assertSame('https://example.com/a.php', $this->api->applicationPasswordAuthorizationUrl([
            'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => 'https://example.com/a.php']]],
        ]));
        $this->assertNull($this->api->applicationPasswordAuthorizationUrl([]));
    }

    public function test_une_url_interne_est_bloquee_avant_tout_appel(): void
    {
        config(['articleguard.ssrf.allowlist' => [], 'articleguard.ssrf.allow_private' => false]);

        $site = WordpressSite::factory()->create(['url' => 'http://169.254.169.254']);
        Http::fake();

        $this->expectException(WordPressApiException::class);

        try {
            $this->api->fetchPost($site, 1);
        } finally {
            Http::assertNothingSent();
        }
    }
}
