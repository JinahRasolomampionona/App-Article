<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les analyses de flou et de cohérence ne portent plus sur la section hero
 * (image à la une affichée en bandeau par le thème, ou autre taille de cette
 * même image dans le contenu).
 *
 * Les remarques déjà enregistrées sur ces images sont supprimées, et non
 * clôturées : les clôturer ferait passer l'article pour « Corrigé » alors que
 * rien n'a été corrigé — c'est le périmètre de l'analyse qui a changé.
 */
return new class extends Migration
{
    private const TYPES = ['image_blurry', 'image_low_resolution', 'image_possibly_incoherent'];

    public function up(): void
    {
        $featured = DB::table('wordpress_articles')
            ->whereNotNull('featured_media_url')
            ->pluck('featured_media_url', 'id');

        $toDelete = [];
        $articles = [];

        DB::table('article_audit_issues')
            ->whereIn('rule_type', self::TYPES)
            ->select(['id', 'wordpress_article_id', 'metadata'])
            ->orderBy('id')
            ->chunk(500, function ($issues) use ($featured, &$toDelete, &$articles) {
                foreach ($issues as $issue) {
                    $metadata = json_decode((string) $issue->metadata, true) ?: [];
                    $heroUrl = $featured[$issue->wordpress_article_id] ?? null;

                    $isHero = ($metadata['target'] ?? null) === 'featured_image'
                        || ($metadata['scope'] ?? null) === 'featured'
                        || ($heroUrl && isset($metadata['src']) && $this->mediaKey($metadata['src']) === $this->mediaKey($heroUrl));

                    if ($isHero) {
                        $toDelete[] = $issue->id;
                        $articles[$issue->wordpress_article_id] = true;
                    }
                }
            });

        foreach (array_chunk($toDelete, 500) as $ids) {
            DB::table('article_audit_issues')->whereIn('id', $ids)->delete();
        }

        // Compteurs et statut recalculés comme le fait le moteur d'audit.
        foreach (array_keys($articles) as $articleId) {
            $open = DB::table('article_audit_issues')
                ->where('wordpress_article_id', $articleId)
                ->whereNull('resolved_at')
                ->count();

            $update = ['issues_count' => $open];

            if ($open === 0) {
                $hadIssues = DB::table('article_audit_issues')
                    ->where('wordpress_article_id', $articleId)
                    ->whereNotNull('resolved_at')
                    ->exists();

                $update['audit_status'] = $hadIssues ? 'fixed' : 'ok';
            }

            DB::table('wordpress_articles')
                ->where('id', $articleId)
                ->where('audit_status', '!=', 'pending')
                ->update($update);
        }
    }

    public function down(): void
    {
        // Les remarques supprimées seront recréées par un nouvel audit si
        // l'analyse de l'image à la une est réactivée.
    }

    private function mediaKey(string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_replace('/(-\d{2,5}x\d{2,5}|-scaled)+(?=\.[a-z0-9]+$)/', '', $path) ?? $path;
    }
};
