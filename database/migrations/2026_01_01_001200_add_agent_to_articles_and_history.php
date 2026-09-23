<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent chargé de corriger un article.
 *
 * Le nom est stocké tel quel plutôt que référencé : les agents ne sont pas des
 * comptes applicatifs, et l'historique doit rester lisible même si la liste
 * configurée change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wordpress_articles', 'agent')) {
            Schema::table('wordpress_articles', function (Blueprint $table) {
                $table->string('agent', 64)->nullable()->after('audit_status');
                $table->timestamp('agent_assigned_at')->nullable()->after('agent');

                $table->index(['wordpress_site_id', 'agent'], 'wp_articles_site_agent_idx');
            });
        }

        if (! Schema::hasColumn('article_status_history', 'agent')) {
            Schema::table('article_status_history', function (Blueprint $table) {
                $table->string('agent', 64)->nullable()->after('resolved_manually');

                $table->index(['user_id', 'agent', 'recorded_at'], 'ash_user_agent_date_idx');
            });
        }

        // Une entrée d'historique déjà créée pour un article encore assigné
        // reprend son agent : sans cela, l'historique existant serait
        // définitivement anonyme.
        $this->backfillAgents();
    }

    protected function backfillAgents(): void
    {
        DB::table('article_status_history as h')
            ->join('wordpress_articles as a', 'a.id', '=', 'h.wordpress_article_id')
            ->whereNull('h.agent')
            ->whereNotNull('a.agent')
            ->select('h.id', 'a.agent')
            ->orderBy('h.id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('article_status_history')
                        ->where('id', $row->id)
                        ->update(['agent' => $row->agent]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('wordpress_articles', function (Blueprint $table) {
            $table->dropIndex('wp_articles_site_agent_idx');
            $table->dropColumn(['agent', 'agent_assigned_at']);
        });

        Schema::table('article_status_history', function (Blueprint $table) {
            $table->dropIndex('ash_user_agent_date_idx');
            $table->dropColumn('agent');
        });
    }
};
