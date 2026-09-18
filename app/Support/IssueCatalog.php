<?php

namespace App\Support;

/**
 * Traduction des types de problèmes en libellés courts et en repères visuels.
 *
 * Centraliser ces libellés garantit qu'un même problème s'énonce de la même
 * façon dans le tableau, dans le modal de détail et dans la colonne d'audit de
 * l'éditeur.
 */
class IssueCatalog
{
    /**
     * @var array<string, array{label: string, short: string, icon: string, target: string}>
     */
    protected const TYPES = [
        'featured_image_missing' => [
            'label' => 'Image à la une manquante',
            'short' => 'Image à la une',
            'icon' => 'image',
            'target' => 'featured_image',
        ],
        'featured_image_unresolved' => [
            'label' => 'Image à la une introuvable',
            'short' => 'Image à la une',
            'icon' => 'image',
            'target' => 'featured_image',
        ],
        'featured_image_unreachable' => [
            'label' => 'Image à la une inaccessible',
            'short' => 'Image à la une',
            'icon' => 'image',
            'target' => 'featured_image',
        ],
        'body_image_missing' => [
            'label' => 'Image dans le contenu manquante',
            'short' => 'Image contenu',
            'icon' => 'image',
            'target' => 'content',
        ],
        'body_image_broken' => [
            'label' => 'Image cassée dans le contenu',
            'short' => 'Image cassée',
            'icon' => 'image',
            'target' => 'content',
        ],
        'image_blurry' => [
            'label' => 'Image potentiellement floue',
            'short' => 'Netteté',
            'icon' => 'blur',
            'target' => 'images',
        ],
        'image_low_resolution' => [
            'label' => 'Image de faible résolution',
            'short' => 'Résolution',
            'icon' => 'blur',
            'target' => 'images',
        ],
        'image_possibly_incoherent' => [
            'label' => 'Image potentiellement incohérente',
            'short' => 'Cohérence',
            'icon' => 'question',
            'target' => 'images',
        ],
        'shortcode_detected' => [
            'label' => 'Shortcode ou crochet détecté',
            'short' => 'Crochet',
            'icon' => 'code',
            'target' => 'content',
        ],
        'long_title' => [
            'label' => 'H1 trop long',
            'short' => 'H1 long',
            'icon' => 'text',
            'target' => 'title',
        ],
        'multiple_h1' => [
            'label' => 'Plusieurs balises H1 détectées',
            'short' => 'H1 multiples',
            'icon' => 'heading',
            'target' => 'content',
        ],
        'missing_h1' => [
            'label' => 'H1 manquant',
            'short' => 'H1',
            'icon' => 'heading',
            'target' => 'content',
        ],
        'missing_h2' => [
            'label' => 'H2 manquant',
            'short' => 'H2',
            'icon' => 'heading',
            'target' => 'content',
        ],
    ];

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
    }

    public static function short(string $type): string
    {
        return self::TYPES[$type]['short'] ?? self::label($type);
    }

    public static function icon(string $type): string
    {
        return self::TYPES[$type]['icon'] ?? 'alert';
    }

    /**
     * Zone de l'éditeur concernée : permet de rendre chaque remarque cliquable.
     */
    public static function target(string $type): string
    {
        return self::TYPES[$type]['target'] ?? 'content';
    }

    /**
     * @return array<string, array{label: string, short: string, icon: string, target: string}>
     */
    public static function all(): array
    {
        return self::TYPES;
    }
}
