<?php

use App\Models\ArticleAuditIssue;
use App\Models\WordpressArticle;
use App\Services\Audit\Relevance\HeuristicImageRelevanceAnalyzer;
use App\Support\HtmlContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'heuristique de cohérence des images a été corrigée : les accents étaient
 * mal découpés sous Windows, et une image reprenant le sujet du titre pouvait
 * être jugée incohérente parce que son alt, rédigé en phrase, diluait les
 * mots-clés.
 *
 * Les remarques « Image potentiellement incohérente » que l'heuristique
 * corrigée ne confirme plus sont supprimées — et non clôturées, pour ne pas
 * faire passer l'article pour « Corrigé ».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('articleguard.relevance.driver', 'heuristic') !== 'heuristic') {
            return;
        }

        $analyzer = new HeuristicImageRelevanceAnalyzer;
        $threshold = (float) config('articleguard.thresholds.relevance', 0.35);
        $articles = [];

        ArticleAuditIssue::query()
            ->where('rule_type', 'image_possibly_incoherent')
            ->whereNull('resolved_at')
            ->with('article')
            ->chunkById(200, function ($issues) use ($analyzer, $threshold, &$articles) {
                foreach ($issues as $issue) {
                    $article = $issue->article;
                    $src = (string) ($issue->metadata['src'] ?? '');

                    if (! $article || $src === '') {
                        continue;
                    }

                    $html = HtmlContent::make($article->content);
                    $image = collect($html->images())->firstWhere('src', $src) ?? [];

                    $result = $analyzer->analyze(
                        [
                            'url' => $src,
                            'alt' => $image['alt'] ?? '',
                            'title' => $image['title'] ?? '',
                            'caption' => $image['caption'] ?? '',
                        ],
                        [
                            'title' => (string) $article->title,
                            'excerpt' => (string) $article->excerpt,
                            'text' => $html->plainText(),
                        ],
                    );

                    if ($result->isPossiblyIncoherent() && $result->score <= $threshold) {
                        continue;
                    }

                    $issue->delete();
                    $articles[$article->id] = true;
                }
            });

        // Compteurs et statut recalculés comme le fait le moteur d'audit.
        foreach (array_keys($articles) as $articleId) {
            $open = DB::table('article_audit_issues')
                ->where('wordpress_article_id', $articleId)
                ->whereNull('resolved_at')
                ->count();

            $update = ['issues_count' => $open];

            if ($open === 0) {
                $update['audit_status'] = DB::table('article_audit_issues')
                    ->where('wordpress_article_id', $articleId)
                    ->whereNotNull('resolved_at')
                    ->exists() ? WordpressArticle::AUDIT_FIXED : WordpressArticle::AUDIT_OK;
            }

            DB::table('wordpress_articles')
                ->where('id', $articleId)
                ->where('audit_status', '!=', WordpressArticle::AUDIT_PENDING)
                ->update($update);
        }
    }

    public function down(): void
    {
        // Un nouvel audit recrée les remarques que l'heuristique confirme.
    }
};
