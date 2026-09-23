<?php

namespace App\Services\WordPress;

use App\Models\WordpressSite;
use App\Support\UnsafeUrlException;
use App\Support\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unique point de contact avec l'API REST WordPress.
 *
 * Aucun contrôleur ne doit appeler `Http::` directement : toute la gestion des
 * timeouts, des retries, de la pagination et de la traduction des erreurs HTTP
 * en messages compréhensibles est centralisée ici.
 */
class WordPressApiService
{
    /** Champs récupérés pour un article : limite fortement le volume transféré. */
    /** Plancher du repli : en dessous, la lenteur ne vient plus de la taille. */
    private const MIN_POSTS_PER_PAGE = 5;

    /**
     * Champs d'un article en lecture publique.
     *
     * `excerpt` est volontairement absent : faute d'extrait saisi, WordPress
     * le fabrique en appliquant une seconde fois tous les filtres
     * `the_content` — mesuré, cela double le temps de réponse. L'extrait est
     * déduit localement du contenu (voir PostMapper).
     */
    private const POST_FIELDS = 'id,date,date_gmt,modified,modified_gmt,slug,link,title,content,featured_media,categories,status,author';

    /**
     * Champs d'un article en `context=edit` (listes, lecture, réponse d'écriture).
     *
     * Ne demander que `raw` évite à WordPress d'appliquer les filtres
     * `the_content` (shortcodes, blocs, plugins…) pour produire un `rendered`
     * que l'application n'utilise pas : sur un site chargé, c'est l'essentiel
     * du temps de réponse. `excerpt.raw` est l'extrait saisi, sans calcul.
     */
    private const EDIT_POST_FIELDS = 'id,date,date_gmt,modified,modified_gmt,slug,link,title.raw,content.raw,excerpt.raw,featured_media,categories,status,author';

    public function __construct(
        protected UrlGuard $guard,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Connexion
    |--------------------------------------------------------------------------
    */

    /**
     * Teste la joignabilité du site et, si des credentials existent, la validité
     * de l'Application Password.
     *
     * @return array{reachable: bool, authenticated: bool, name: ?string, description: ?string, wp_user: ?string, wp_user_login: ?string, can_edit: ?bool, role: ?string, supports_application_passwords: bool, authorization_endpoint: ?string}
     */
    public function testConnection(WordpressSite $site): array
    {
        $root = $this->fetchApiRoot($site);

        $result = [
            'reachable' => true,
            'authenticated' => false,
            'name' => is_string($root['name'] ?? null) ? $root['name'] : null,
            'description' => is_string($root['description'] ?? null) ? $root['description'] : null,
            'wp_user' => null,
            'wp_user_login' => null,
            'can_edit' => null,
            'role' => null,
            'supports_application_passwords' => $this->supportsApplicationPasswords($root),
            'authorization_endpoint' => $this->applicationPasswordAuthorizationUrl($root),
        ];

        if (! $site->hasCredentials()) {
            return $result;
        }

        // /users/me est le test d'authentification le plus léger et le plus fiable.
        try {
            $me = $this->get($site, $this->endpoint($site, '/users/me'), [
                'context' => 'edit',
                '_fields' => 'id,name,slug,username,roles,capabilities',
            ]);
        } catch (WordPressApiException $e) {
            // Le site n'annonce pas du tout les Application Passwords : inutile
            // de renvoyer l'utilisateur vers son profil WordPress, la cause est
            // ailleurs (HTTPS absent, ou fonctionnalité désactivée par un plugin).
            if ($e->reason === 'unauthorized' && ! $result['supports_application_passwords']) {
                throw WordPressApiException::unauthorized(
                    $e->context['detail'] ?? null,
                    'application_passwords_disabled',
                );
            }

            throw $e;
        }

        $result['authenticated'] = true;
        $result['wp_user'] = is_string($me['name'] ?? null) ? $me['name'] : null;
        $result['wp_user_login'] = is_string($me['username'] ?? null) ? $me['username'] : null;
        $result['can_edit'] = $this->canEditPosts($me);
        $result['role'] = $this->primaryRole($me);

        return $result;
    }

    /**
     * Le compte peut-il éditer des articles ?
     *
     * WordPress expose les capacités du compte courant sur `/users/me` en
     * `context=edit`. `edit_posts` est la capacité minimale qui autorise
     * `context=edit` et `status=any` sur `/posts` : sans elle, l'API répond
     * 403 `rest_forbidden_context` et 400 `rest_forbidden_status`.
     *
     * Renvoie `null` lorsque le site n'expose pas les capacités : on ne
     * conclut pas à partir d'une information absente.
     *
     * @param  array<string, mixed>  $me
     */
    protected function canEditPosts(array $me): ?bool
    {
        $capabilities = $me['capabilities'] ?? null;

        if (! is_array($capabilities) || $capabilities === []) {
            return null;
        }

        return (bool) ($capabilities['edit_posts'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $me
     */
    protected function primaryRole(array $me): ?string
    {
        $roles = $me['roles'] ?? null;

        if (! is_array($roles) || $roles === []) {
            return null;
        }

        $role = reset($roles);

        return is_string($role) ? $role : null;
    }

    /**
     * Racine de l'API REST, réduite aux champs utiles : la réponse complète
     * peut peser plusieurs centaines de kilo-octets sur un site chargé.
     *
     * @return array<string, mixed>
     */
    public function fetchApiRoot(WordpressSite $site): array
    {
        $root = $this->get($site, $this->apiRoot($site), [
            '_fields' => 'name,description,url,home,gmt_offset,namespaces,authentication',
        ], authenticated: false);

        if (! isset($root['namespaces']) && ! isset($root['name'])) {
            throw WordPressApiException::notWordPress($site->url);
        }

        return $root;
    }

    /**
     * WordPress annonce lui-même les modes d'authentification disponibles dans
     * la clé `authentication` de la racine REST. L'absence de
     * `application-passwords` signifie que le site ne les acceptera jamais,
     * quelle que soit la valeur envoyée.
     *
     * @param  array<string, mixed>  $root
     */
    public function supportsApplicationPasswords(array $root): bool
    {
        $authentication = $root['authentication'] ?? null;

        return is_array($authentication) && array_key_exists('application-passwords', $authentication);
    }

    /**
     * URL WordPress de création d'une Application Password, telle qu'annoncée
     * par le site.
     *
     * @param  array<string, mixed>  $root
     */
    public function applicationPasswordAuthorizationUrl(array $root): ?string
    {
        $endpoint = data_get($root, 'authentication.application-passwords.endpoints.authorization');

        return is_string($endpoint) ? $endpoint : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Catégories
    |--------------------------------------------------------------------------
    */

    /**
     * Récupère toutes les catégories du site en suivant la pagination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllCategories(WordpressSite $site): array
    {
        $categories = [];
        $page = 1;

        do {
            $result = $this->fetchCategoriesPage($site, $page);
            $categories = array_merge($categories, $result->items);
            $page++;
        } while ($result->hasMorePages() && $page <= 50);

        return $categories;
    }

    public function fetchCategoriesPage(WordpressSite $site, int $page = 1): PaginatedResult
    {
        return $this->getPaginated($site, $this->endpoint($site, '/categories'), [
            'page' => $page,
            'per_page' => $this->perPage(),
            'orderby' => 'name',
            'order' => 'asc',
            '_fields' => 'id,name,slug,parent,count',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Articles
    |--------------------------------------------------------------------------
    */

    /**
     * Une page d'articles. `context=edit` n'est demandé que si le compte
     * WordPress a le droit d'éditer : c'est le seul moyen d'obtenir le HTML
     * brut (`raw`) réellement éditable, ainsi que les brouillons.
     *
     * Si WordPress refuse malgré tout ces paramètres — capacités non exposées
     * par le site, rôle modifié depuis le dernier test —, la requête est
     * rejouée en lecture seule plutôt que d'abandonner la synchronisation :
     * les articles publiés restent parfaitement lisibles.
     *
     * @param  array<string, mixed>  $extra
     */
    public function fetchPostsPage(WordpressSite $site, int $page = 1, array $extra = [], bool $editContext = true): PaginatedResult
    {
        $params = array_merge([
            'page' => $page,
            'per_page' => $this->postsPerPage(),
            'orderby' => 'date',
            'order' => 'desc',
            '_fields' => self::POST_FIELDS,
        ], $extra);

        $url = $this->endpoint($site, '/posts');

        if (! $editContext || ! $site->canEditContent()) {
            return $this->getPaginated($site, $url, $params);
        }

        $editParams = array_merge($params, [
            'context' => 'edit',
            'status' => 'any',
            '_fields' => self::EDIT_POST_FIELDS,
        ]);

        try {
            return $this->getPaginated($site, $url, $editParams);
        } catch (WordPressApiException $e) {
            if (! $this->isEditContextRefusal($e)) {
                throw $e;
            }

            Log::info('Compte WordPress sans droit d’édition : lecture des articles publiés uniquement', [
                'site_id' => $site->id,
                'wp_code' => $e->wpCode(),
            ]);

            $site->markReadOnlyAccount();

            return $this->getPaginated($site, $url, $params);
        }
    }

    /**
     * WordPress refuse-t-il la requête à cause de `context=edit` ou de
     * `status=any` plutôt qu'à cause des identifiants ?
     *
     * - 403 `rest_forbidden_context` : `context=edit` demandé sans `edit_posts`.
     * - 400 `rest_invalid_param` dont le détail est `rest_forbidden_status` :
     *   `status=any` demandé sans droit de voir les articles non publiés.
     *
     * Le repli n'a de sens que pour ces deux paramètres : un 400 portant sur
     * autre chose doit remonter tel quel plutôt que d'être rejoué à l'aveugle.
     */
    protected function isEditContextRefusal(WordPressApiException $e): bool
    {
        if (! in_array($e->status, [400, 403], true)) {
            return false;
        }

        if (in_array($e->wpCode(), ['rest_forbidden_context', 'rest_forbidden_status'], true)) {
            return true;
        }

        // Les WordPress anciens ne détaillent pas la cause par paramètre :
        // seule la liste des paramètres refusés permet de conclure.
        $refused = $e->wpParams();

        return $refused !== [] && array_diff($refused, ['status', 'context']) === [];
    }

    /**
     * Parcourt tous les articles page par page.
     *
     * Le callback reçoit chaque page : la mémoire ne grandit pas avec la taille
     * du site, ce qui permet de traiter plusieurs milliers d'articles.
     *
     * @param  callable(array<int, array<string, mixed>>, PaginatedResult): void  $onPage
     * @return int Nombre d'articles parcourus
     */
    public function eachPost(WordpressSite $site, callable $onPage, int $maxArticles = 5000): int
    {
        $perPage = $this->postsPerPage();
        $page = 1;
        $seen = 0;
        $editContext = true;

        // Boucle à sorties explicites : un `continue` dans un `do … while`
        // évaluerait la condition sur la page qu'on vient d'écarter.
        while (true) {
            try {
                $result = $this->fetchPostsPage($site, $page, ['per_page' => $perPage], $editContext);

                if ($editContext && $site->canEditContent() && $this->editListingIsFiltered($site, $result)) {
                    // Toute la liste est relue en mode public, depuis le début :
                    // les deux modes ne numérotent pas les pages de la même façon
                    // (brouillons inclus ou non). Les pages déjà traitées sont
                    // simplement réécrites à l'identique.
                    $editContext = false;
                    $page = 1;
                    $seen = 0;

                    continue;
                }
            } catch (WordPressApiException $e) {
                $smaller = $this->smallerPageSize($perPage, $seen);

                // Page trop lourde pour la connexion : on la redemande en plus
                // petit plutôt que d'abandonner toute la synchronisation.
                if ($e->reason !== 'unreachable' || $smaller === null) {
                    throw $e;
                }

                Log::info('Page d’articles trop lente, taille réduite', [
                    'site_id' => $site->id,
                    'per_page' => $perPage,
                    'next_per_page' => $smaller,
                ]);

                // Pages déjà lues toutes pleines : `$seen` est un multiple de
                // la nouvelle taille, la reprise se fait sans trou ni doublon.
                $perPage = $smaller;
                $page = intdiv($seen, $perPage) + 1;

                continue;
            }

            if ($result->items === []) {
                break;
            }

            $onPage($result->items, $result);
            $seen += count($result->items);
            $page++;

            if (! $result->hasMorePages() || $seen >= $maxArticles) {
                break;
            }
        }

        return $seen;
    }

    /**
     * La page lue en `context=edit` a-t-elle été amputée par WordPress ?
     *
     * En `context=edit`, WordPress retire silencieusement de la réponse les
     * articles que le compte ne peut pas modifier — tous ceux des autres
     * rédacteurs pour un compte « Auteur ». Les en-têtes `X-WP-Total` comptent
     * pourtant ces articles : la page revient vide ou incomplète sans erreur,
     * et la synchronisation concluait à tort que le site n'avait aucun
     * article. Seul le nombre d'éléments réellement reçus le révèle.
     */
    protected function editListingIsFiltered(WordpressSite $site, PaginatedResult $result): bool
    {
        $expected = max(0, min($result->perPage, $result->total - ($result->page - 1) * $result->perPage));

        // Certains plugins de permissions remettent aussi le total à zéro :
        // une première page vide vaut une vérification en mode public.
        if ($result->page === 1 && $result->items === []) {
            $expected = 1;
        }

        if (count($result->items) >= $expected) {
            return false;
        }

        Log::warning('Liste d’articles restreinte en mode édition : lecture des articles publiés', [
            'site_id' => $site->id,
            'page' => $result->page,
            'expected' => $expected,
            'received' => count($result->items),
        ]);

        return true;
    }

    /**
     * Taille de page réduite de moitié, ou `null` au plancher. Elle doit
     * diviser `$seen` pour que le calcul de la page suivante reste exact.
     */
    protected function smallerPageSize(int $perPage, int $seen): ?int
    {
        for ($size = intdiv($perPage, 2); $size >= self::MIN_POSTS_PER_PAGE; $size--) {
            if ($seen % $size === 0) {
                return $size;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPost(WordpressSite $site, int $wpId): array
    {
        $params = ['_fields' => self::POST_FIELDS];
        $url = $this->endpoint($site, '/posts/'.$wpId);

        if (! $site->canEditContent()) {
            return $this->get($site, $url, $params);
        }

        try {
            return $this->get($site, $url, ['context' => 'edit', '_fields' => self::EDIT_POST_FIELDS]);
        } catch (WordPressApiException $e) {
            if (! $this->isEditContextRefusal($e)) {
                throw $e;
            }

            $site->markReadOnlyAccount();

            return $this->get($site, $url, $params);
        }
    }

    /**
     * Met à jour un article. Seuls les champs réellement modifiés doivent être
     * transmis par l'appelant.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updatePost(WordpressSite $site, int $wpId, array $payload): array
    {
        if ($payload === []) {
            return $this->fetchPost($site, $wpId);
        }

        if (! $site->hasCredentials()) {
            throw WordPressApiException::unauthorized('Aucun identifiant WordPress enregistré pour ce site.');
        }

        $url = $this->endpoint($site, '/posts/'.$wpId);
        $query = http_build_query(['context' => 'edit', '_fields' => self::EDIT_POST_FIELDS]);
        $timeout = max((int) config('articleguard.http.timeout'), (int) config('articleguard.http.write_timeout', 90));

        $response = $this->send(
            $site,
            fn (PendingRequest $request) => $request->timeout($timeout)->post($url.'?'.$query, $payload),
            $url,
            write: true,
        );

        return $this->decode($response);
    }

    /**
     * Version d'édition d'un article, allégée comme la réponse d'une écriture.
     *
     * Sert à vérifier une mise à jour dont la réponse n'est pas arrivée :
     * WordPress a souvent terminé l'enregistrement alors que la connexion a
     * expiré de notre côté.
     *
     * @return array<string, mixed>
     */
    public function fetchPostForEdit(WordpressSite $site, int $wpId): array
    {
        return $this->get($site, $this->endpoint($site, '/posts/'.$wpId), [
            'context' => 'edit',
            '_fields' => self::EDIT_POST_FIELDS,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Médias
    |--------------------------------------------------------------------------
    */

    /**
     * Champs d'un média : couvre le panneau « Détails du fichier joint » de
     * WordPress (texte alternatif, titre, légende, description, URL) ainsi que
     * les déclinaisons de taille proposées à l'insertion.
     */
    private const MEDIA_FIELDS = 'id,source_url,alt_text,mime_type,media_details,title,caption,description,slug,date';

    /**
     * @return array<string, mixed>|null
     */
    public function fetchMedia(WordpressSite $site, int $mediaId): ?array
    {
        if ($mediaId <= 0) {
            return null;
        }

        try {
            return $this->get($site, $this->endpoint($site, '/media/'.$mediaId), [
                '_fields' => self::MEDIA_FIELDS,
            ]);
        } catch (WordPressApiException $e) {
            if ($e->reason === 'not_found') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Récupère plusieurs médias en une requête (`include`), en limitant le
     * nombre d'allers-retours lors d'une synchronisation.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>> indexé par identifiant WordPress
     */
    public function fetchMediaByIds(WordpressSite $site, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $media = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            $result = $this->getPaginated($site, $this->endpoint($site, '/media'), [
                'include' => implode(',', $chunk),
                'per_page' => count($chunk),
                '_fields' => 'id,source_url,alt_text,mime_type,media_details',
            ]);

            foreach ($result->items as $item) {
                if (isset($item['id'])) {
                    $media[(int) $item['id']] = $item;
                }
            }
        }

        return $media;
    }

    public function searchMedia(WordpressSite $site, ?string $search = null, int $page = 1, int $perPage = 24): PaginatedResult
    {
        return $this->getPaginated($site, $this->endpoint($site, '/media'), array_filter([
            'search' => $search ?: null,
            'media_type' => 'image',
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => 'date',
            'order' => 'desc',
            '_fields' => self::MEDIA_FIELDS,
        ], fn ($value) => $value !== null));
    }

    /**
     * Met à jour les métadonnées d'un média (texte alternatif, titre, légende,
     * description). Ces champs appartiennent à la médiathèque : les modifier
     * les change partout où le média est utilisé sur le site.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateMedia(WordpressSite $site, int $mediaId, array $payload): array
    {
        if ($mediaId <= 0) {
            throw WordPressApiException::notFound('Identifiant de média invalide.');
        }

        if ($payload === []) {
            return $this->fetchMedia($site, $mediaId) ?? [];
        }

        if (! $site->hasCredentials()) {
            throw WordPressApiException::unauthorized('Aucun identifiant WordPress enregistré pour ce site.');
        }

        $url = $this->endpoint($site, '/media/'.$mediaId);
        $query = http_build_query(['_fields' => self::MEDIA_FIELDS]);

        $response = $this->send(
            $site,
            fn (PendingRequest $request) => $request->post($url.'?'.$query, $payload),
            $url,
            write: true,
        );

        return $this->decode($response);
    }

    /**
     * Téléverse un fichier dans la médiathèque WordPress.
     *
     * @return array<string, mixed>
     */
    public function uploadMedia(WordpressSite $site, string $contents, string $filename, string $mime): array
    {
        if (! $site->hasCredentials()) {
            throw WordPressApiException::unauthorized('Aucun identifiant WordPress enregistré pour ce site.');
        }

        $url = $this->endpoint($site, '/media');

        $response = $this->send(
            $site,
            fn (PendingRequest $request) => $request
                ->withBody($contents, $mime)
                ->withHeaders(['Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"'])
                ->post($url),
            $url,
        );

        return $this->decode($response);
    }

    /*
    |--------------------------------------------------------------------------
    | Plomberie HTTP
    |--------------------------------------------------------------------------
    */

    public function apiRoot(WordpressSite $site): string
    {
        return rtrim($site->url, '/').'/wp-json';
    }

    public function endpoint(WordpressSite $site, string $path): string
    {
        return $this->apiRoot($site).'/wp/v2'.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(WordpressSite $site, string $url, array $query = [], bool $authenticated = true): array
    {
        $response = $this->send(
            $site,
            fn (PendingRequest $request) => $request->get($url, $query),
            $url,
            $authenticated,
        );

        return $this->decode($response);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function getPaginated(WordpressSite $site, string $url, array $query = []): PaginatedResult
    {
        $response = $this->send(
            $site,
            fn (PendingRequest $request) => $request->get($url, $query),
            $url,
        );

        $items = $this->decode($response);

        if (! array_is_list($items)) {
            $items = [];
        }

        $perPage = (int) ($query['per_page'] ?? $this->perPage());
        $page = (int) ($query['page'] ?? 1);

        // WordPress expose le volume total via ces deux en-têtes ; ils sont
        // absents lorsqu'on interroge un endpoint non paginé.
        $total = (int) ($response->header('X-WP-Total') ?: count($items));
        $totalPages = (int) ($response->header('X-WP-TotalPages') ?: 1);

        return new PaginatedResult($items, $page, $perPage, $total, max(1, $totalPages));
    }

    /**
     * Exécute la requête et convertit toute anomalie en WordPressApiException.
     *
     * @param  callable(PendingRequest): Response  $callback
     */
    protected function send(WordpressSite $site, callable $callback, string $url, bool $authenticated = true, bool $write = false): Response
    {
        try {
            $this->guard->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            throw new WordPressApiException($e->getMessage(), 'unsafe_url', null, ['url' => $url], $e);
        }

        try {
            $response = $callback($this->request($site, $authenticated, $write));
        } catch (ConnectionException $e) {
            Log::warning('WordPress injoignable', [
                'site_id' => $site->id,
                'url' => $url,
                'detail' => $e->getMessage(),
            ]);

            throw WordPressApiException::unreachable($url, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->translate($response, $site, $url);
    }

    /**
     * @param  bool  $write  Écriture : on ne la rejoue que si elle n'a jamais
     *                       atteint WordPress. Un délai dépassé en attendant la
     *                       réponse signifie que WordPress traite déjà
     *                       l'écriture ; la renvoyer doublerait l'attente.
     */
    protected function request(WordpressSite $site, bool $authenticated = true, bool $write = false): PendingRequest
    {
        $config = config('articleguard.http');

        $request = Http::acceptJson()
            ->withHeaders([
                'User-Agent' => $config['user_agent'],
                // Le JSON des articles se compresse environ 4 fois : décisif sur
                // une connexion lente. Guzzle décompresse la réponse lui-même.
                'Accept-Encoding' => 'gzip, deflate',
            ])
            ->timeout($config['timeout'])
            ->connectTimeout($config['connect_timeout'])
            ->retry(
                max(1, $config['retry_times']),
                $config['retry_sleep'],
                // Un 4xx ne sera jamais résolu par un nouvel essai.
                fn ($exception) => $exception instanceof ConnectionException
                    && (! $write || ! $this->isResponseTimeout($exception)),
                throw: false,
            );

        if ($authenticated && $site->hasCredentials()) {
            $request = $request->withBasicAuth(
                (string) $site->wp_username,
                (string) $site->application_password,
            );
        }

        return $request;
    }

    /**
     * cURL 28 « Operation timed out » : la connexion était établie et la
     * requête envoyée, seule la réponse a manqué.
     */
    public function isResponseTimeout(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'Operation timed out');
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new WordPressApiException(
                'Réponse inattendue du site WordPress. L’API REST ne renvoie pas de JSON valide.',
                'invalid_response',
                $response->status(),
                ['body' => mb_substr($response->body(), 0, 500)],
            );
        }

        return $data;
    }

    protected function translate(Response $response, WordpressSite $site, string $url): WordPressApiException
    {
        $body = $response->json();
        $detail = is_array($body) && isset($body['message']) && is_string($body['message'])
            ? strip_tags($body['message'])
            : null;
        $code = is_array($body) && isset($body['code']) && is_string($body['code'])
            ? $body['code']
            : null;

        // Sur un paramètre refusé, WordPress renvoie `rest_invalid_param` et
        // range la cause réelle (`rest_forbidden_status`…) dans
        // `data.details.<param>.code` : c'est elle qui est exploitable.
        if ($code === 'rest_invalid_param') {
            $code = $this->detailedCode($body) ?? $code;
        }

        Log::warning('Erreur API WordPress', [
            'site_id' => $site->id,
            'url' => $url,
            'status' => $response->status(),
            'code' => $code,
            'detail' => $detail,
        ]);

        return match (true) {
            $response->status() === 400 => WordPressApiException::invalidData($detail, $code, $this->refusedParams($body)),
            $response->status() === 401 => WordPressApiException::unauthorized($detail, $code),
            $response->status() === 403 => WordPressApiException::forbidden($detail, $code),
            $response->status() === 404 => str_contains($url, '/wp-json')
                && ! str_contains($url, '/wp/v2/')
                    ? WordPressApiException::notWordPress($site->url)
                    : WordPressApiException::notFound($detail),
            $response->status() === 429 => WordPressApiException::rateLimited(),
            $response->serverError() => WordPressApiException::serverError($response->status(), $detail),
            default => new WordPressApiException(
                'Le site WordPress a renvoyé une réponse inattendue ('.$response->status().').',
                'http_error',
                $response->status(),
                ['detail' => $detail],
            ),
        };
    }

    /**
     * Premier code d'erreur détaillé d'une réponse `rest_invalid_param`.
     *
     * @param  mixed  $body
     */
    protected function detailedCode($body): ?string
    {
        $details = data_get($body, 'data.details');

        if (! is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            $code = $detail['code'] ?? null;

            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return null;
    }

    /**
     * Paramètres refusés par une réponse `rest_invalid_param`.
     *
     * WordPress les liste dans `data.params`, indépendamment de la langue du
     * site — contrairement au message.
     *
     * @param  mixed  $body
     * @return array<int, string>
     */
    protected function refusedParams($body): array
    {
        $params = data_get($body, 'data.params');

        if (! is_array($params)) {
            return [];
        }

        return array_values(array_filter(array_keys($params), 'is_string'));
    }

    protected function perPage(): int
    {
        return (int) config('articleguard.http.per_page', 100);
    }

    /**
     * Les articles transportent leur contenu complet (en double avec
     * `context=edit`) : des pages plus courtes que pour les catégories.
     */
    protected function postsPerPage(): int
    {
        return max(self::MIN_POSTS_PER_PAGE, min($this->perPage(), (int) config('articleguard.http.posts_per_page', 20)));
    }
}
