<?php

/**
 * Plugin Name:       ArticleGuard Cache Bridge
 * Description:       Permet à ArticleGuard WP de vider le cache d'un article juste après l'avoir corrigé (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer…).
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            ArticleGuard WP
 * License:           GPL-2.0-or-later
 *
 * Expose deux routes REST, authentifiées comme le reste de l'API WordPress
 * (Application Password du compte utilisé par ArticleGuard) :
 *
 *   GET  /wp-json/articleguard/v1/status       extension active, caches détectés
 *   POST /wp-json/articleguard/v1/purge/{id}   vide le cache de l'article {id}
 *
 * Seul un compte autorisé à modifier l'article peut en vider le cache. Rien
 * d'autre n'est exposé : aucune donnée n'est lue ni modifiée.
 */
if (! defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('articleguard/v1', '/status', [
        'methods' => 'GET',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => function () {
            return rest_ensure_response([
                'plugin' => 'articleguard-cache-bridge',
                'version' => '1.0.0',
                'caches' => articleguard_detected_caches(),
            ]);
        },
    ]);

    register_rest_route('articleguard/v1', '/purge/(?P<id>\d+)', [
        'methods' => 'POST',
        'args' => [
            'id' => ['validate_callback' => function ($value) {
                return is_numeric($value) && (int) $value > 0;
            }],
        ],
        'permission_callback' => function (WP_REST_Request $request) {
            return current_user_can('edit_post', (int) $request['id']);
        },
        'callback' => function (WP_REST_Request $request) {
            $id = (int) $request['id'];
            $post = get_post($id);

            if (! $post) {
                return new WP_Error('articleguard_not_found', 'Article introuvable.', ['status' => 404]);
            }

            return rest_ensure_response([
                'id' => $id,
                'url' => get_permalink($id),
                'purged' => articleguard_purge_post($id),
            ]);
        },
    ]);
});

/**
 * Caches de page reconnus sur ce site.
 *
 * @return string[]
 */
function articleguard_detected_caches()
{
    $caches = [];

    if (function_exists('rocket_clean_post')) {
        $caches[] = 'WP Rocket';
    }
    if (defined('LSCWP_V') || has_action('litespeed_purge_post')) {
        $caches[] = 'LiteSpeed Cache';
    }
    if (function_exists('w3tc_flush_post')) {
        $caches[] = 'W3 Total Cache';
    }
    if (function_exists('wp_cache_post_change')) {
        $caches[] = 'WP Super Cache';
    }
    if (class_exists('WpFastestCache')) {
        $caches[] = 'WP Fastest Cache';
    }
    if (function_exists('sg_cachepress_purge_cache')) {
        $caches[] = 'SiteGround Optimizer';
    }
    if (class_exists('Breeze_PurgeCache')) {
        $caches[] = 'Breeze';
    }

    return $caches;
}

/**
 * Vide le cache de page de l'article dans chaque extension présente.
 *
 * @return string[] caches effectivement vidés
 */
function articleguard_purge_post($id)
{
    $purged = [];
    $url = get_permalink($id);

    // Cache objet de WordPress lui-même.
    clean_post_cache($id);
    $purged[] = 'WordPress';

    // WP Rocket (et son module Cloudflare, branché sur cette purge).
    if (function_exists('rocket_clean_post')) {
        rocket_clean_post($id);
        $purged[] = 'WP Rocket';
    }

    if (defined('LSCWP_V') || has_action('litespeed_purge_post')) {
        do_action('litespeed_purge_post', $id);
        $purged[] = 'LiteSpeed Cache';
    }

    if (function_exists('w3tc_flush_post')) {
        w3tc_flush_post($id);
        $purged[] = 'W3 Total Cache';
    }

    if (function_exists('wp_cache_post_change')) {
        wp_cache_post_change($id);
        $purged[] = 'WP Super Cache';
    }

    if (class_exists('WpFastestCache')) {
        do_action('wpfc_clear_post_cache_by_id', false, $id);
        $purged[] = 'WP Fastest Cache';
    }

    if (function_exists('sg_cachepress_purge_cache') && $url) {
        sg_cachepress_purge_cache($url);
        $purged[] = 'SiteGround Optimizer';
    }

    if (class_exists('Breeze_PurgeCache') && method_exists('Breeze_PurgeCache', 'breeze_cache_flush')) {
        Breeze_PurgeCache::breeze_cache_flush();
        $purged[] = 'Breeze';
    }

    // Point d'extension pour un cache maison (Varnish, Nginx, CDN…).
    do_action('articleguard_purge_post', $id, $url);

    return $purged;
}
