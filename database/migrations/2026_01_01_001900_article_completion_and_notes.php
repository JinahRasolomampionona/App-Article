<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fin de traitement déclarée par l'agent, et commentaires de l'Admin.
 *
 * - `completed_at` / `completed_by` : l'agent a terminé l'article (« Corrigé »).
 *   Distinct du statut d'audit : l'article quitte la liste des agents et
 *   attend la vérification de l'Admin, qui peut le réassigner ;
 * - `article_status_history.resolved_issues` : les erreurs corrigées par
 *   l'agent, recopiées au moment où il déclare l'article corrigé ;
 * - `article_notes` : commentaires de l'Admin sur ce qui reste à faire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_articles', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('lock_expires_at');
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['wordpress_site_id', 'completed_at'], 'wp_articles_site_completed_idx');
        });

        Schema::table('article_status_history', function (Blueprint $table) {
            $table->json('resolved_issues')->nullable()->after('issues_resolved');
        });

        Schema::create('article_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // Agent à qui s'adresse le commentaire.
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['wordpress_article_id', 'created_at']);
            $table->index('agent_id');
        });

        // Les articles déjà déclarés « Corrigé » à la main sont considérés
        // comme traités, crédités au dernier agent de leur historique.
        DB::table('wordpress_articles')
            ->where('audit_status', 'fixed')
            ->whereNotNull('status_set_manually_at')
            ->orderBy('id')
            ->select(['id', 'status_set_manually_at'])
            ->chunk(500, function ($articles) {
                foreach ($articles as $article) {
                    $agent = DB::table('article_status_history')
                        ->where('wordpress_article_id', $article->id)
                        ->whereNotNull('agent_user_id')
                        ->orderByDesc('recorded_at')
                        ->value('agent_user_id');

                    DB::table('wordpress_articles')->where('id', $article->id)->update([
                        'completed_at' => $article->status_set_manually_at,
                        'completed_by' => $agent,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_notes');

        Schema::table('article_status_history', function (Blueprint $table) {
            $table->dropColumn('resolved_issues');
        });

        Schema::table('wordpress_articles', function (Blueprint $table) {
            $table->dropIndex('wp_articles_site_completed_idx');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn('completed_at');
        });
    }
};
