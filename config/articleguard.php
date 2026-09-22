<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identité du produit
    |--------------------------------------------------------------------------
    */

    'name' => 'ArticleGuard WP',
    'short_name' => 'ArticleGuard',
    'tagline' => 'WordPress Content Audit & Management',

    /*
    |--------------------------------------------------------------------------
    | Client HTTP WordPress
    |--------------------------------------------------------------------------
    |
    | Paramètres appliqués à tous les appels sortants vers l'API REST
    | WordPress. Le timeout est obligatoire : un site distant lent ne doit
    | jamais bloquer une requête applicative.
    |
    */

    'http' => [
        'timeout' => (int) env('WP_HTTP_TIMEOUT', 60),
        // Mise à jour d'un article : WordPress exécute tous les hooks de
        // sauvegarde (SEO, cache…), souvent plus lents qu'une lecture.
        'write_timeout' => (int) env('WP_HTTP_WRITE_TIMEOUT', 90),
        'connect_timeout' => (int) env('WP_HTTP_CONNECT_TIMEOUT', 8),
        'retry_times' => (int) env('WP_HTTP_RETRY_TIMES', 2),
        'retry_sleep' => (int) env('WP_HTTP_RETRY_SLEEP', 300),
        'per_page' => min(100, max(1, (int) env('WP_PER_PAGE', 100))),
        // Articles : contenu complet, pages plus courtes (réduites seules si trop lentes).
        'posts_per_page' => min(100, max(5, (int) env('WP_POSTS_PER_PAGE', 50))),
        'user_agent' => 'ArticleGuardWP/1.0 (+https://articleguard.local)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Protection SSRF
    |--------------------------------------------------------------------------
    |
    | Le domaine WordPress est une saisie utilisateur : il est résolu puis
    | comparé aux plages réservées avant tout appel réseau.
    |
    */

    'ssrf' => [
        'allow_private' => (bool) env('AG_SSRF_ALLOW_PRIVATE', false),
        'allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AG_SSRF_ALLOWLIST', ''))
        ))),
        'allowed_schemes' => ['http', 'https'],
        'allowed_ports' => [80, 443, 8000, 8080, 8443],
    ],

    /*
    |--------------------------------------------------------------------------
    | Règles d'audit
    |--------------------------------------------------------------------------
    |
    | Chaque règle peut être désactivée. Les règles coûteuses (réseau) sont
    | isolées afin de pouvoir n'exécuter que l'audit « rapide » quand le
    | contexte l'impose.
    |
    */

    'rules' => [
        'featured_image' => (bool) env('AG_RULE_FEATURED_IMAGE', true),
        'body_image' => (bool) env('AG_RULE_BODY_IMAGE', false),
        'broken_image' => (bool) env('AG_RULE_BROKEN_IMAGE', true),
        'shortcode' => (bool) env('AG_RULE_SHORTCODE', true),
        'long_title' => (bool) env('AG_RULE_LONG_TITLE', true),
        'h1' => (bool) env('AG_RULE_H1', true),
        'missing_h1' => (bool) env('AG_RULE_MISSING_H1', false),
        'missing_h2' => (bool) env('AG_RULE_MISSING_H2', false),
        'image_blur' => (bool) env('AG_RULE_IMAGE_BLUR', true),
        'image_relevance' => (bool) env('AG_RULE_IMAGE_RELEVANCE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seuils
    |--------------------------------------------------------------------------
    */

    'thresholds' => [
        'title_max_words' => (int) env('AG_TITLE_MAX_WORDS', 20),
        'blur' => (float) env('AG_BLUR_THRESHOLD', 100),
        'min_image_width' => (int) env('AG_MIN_IMAGE_WIDTH', 600),
        'min_image_height' => (int) env('AG_MIN_IMAGE_HEIGHT', 400),
        'relevance' => (float) env('AG_RELEVANCE_THRESHOLD', 0.35),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analyse d'images
    |--------------------------------------------------------------------------
    |
    | Les résultats sont mémorisés en base (table image_analyses) et
    | réutilisés tant que l'URL du média ne change pas.
    |
    */

    'images' => [
        'max_bytes' => (int) env('AG_IMAGE_MAX_BYTES', 8 * 1024 * 1024),
        'download_timeout' => (int) env('AG_IMAGE_DOWNLOAD_TIMEOUT', 12),
        'analysis_ttl_days' => (int) env('AG_IMAGE_ANALYSIS_TTL_DAYS', 30),
        // Côté le plus long de l'image redimensionnée avant calcul du Laplacien.
        'analysis_resize' => 512,
        // Nombre maximum d'images du contenu analysées par article.
        'max_body_images' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pertinence des images
    |--------------------------------------------------------------------------
    |
    | `heuristic` : comparaison locale (nom de fichier, alt, légende) avec le
    |               titre et le contenu de l'article. Aucune dépendance.
    | `null`      : analyse désactivée, retourne toujours « unknown ».
    | `vision`    : fournisseur de vision distant, à configurer.
    |
    */

    'relevance' => [
        'driver' => env('AG_RELEVANCE_DRIVER', 'heuristic'),
        'vision' => [
            'api_key' => env('AG_VISION_API_KEY'),
            'model' => env('AG_VISION_MODEL'),
            'endpoint' => env('AG_VISION_ENDPOINT'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Synchronisation
    |--------------------------------------------------------------------------
    */

    'sync' => [
        // Garde-fou : nombre maximum d'articles récupérés par synchronisation.
        'max_articles' => 5000,
        'articles_per_page' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker de file d'attente automatique
    |--------------------------------------------------------------------------
    |
    | Lorsqu'une synchronisation ou un audit est programmé, l'application
    | démarre elle-même un `queue:work --stop-when-empty` en arrière-plan :
    | aucune commande manuelle n'est nécessaire. À désactiver si un worker
    | permanent (Supervisor, systemd…) est déjà en place.
    |
    */

    'queue' => [
        'autostart' => (bool) env('AG_QUEUE_AUTOSTART', true),
        // Binaire PHP CLI à utiliser (détecté automatiquement si vide).
        'php_binary' => env('AG_PHP_BINARY'),
        // Délai minimal (secondes) entre deux démarrages automatiques.
        'spawn_cooldown' => (int) env('AG_QUEUE_SPAWN_COOLDOWN', 15),
        // Durée de vie maximale d'un worker démarré automatiquement.
        'max_time' => (int) env('AG_QUEUE_MAX_TIME', 3600),
    ],
];
