<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des articles passés à « OK » ou « Corrigé ».
 *
 * Les statistiques doivent survivre à la suppression d'un site : le nom et
 * l'URL du site, comme le titre de l'article, sont donc recopiés ici. Les
 * clés étrangères sont nullables et passent à `null` à la suppression, ce qui
 * conserve la ligne tout en gardant le lien tant que la source existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('wordpress_site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_name', 191);
            $table->string('site_url', 512)->nullable();

            $table->foreignId('wordpress_article_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('wp_id')->nullable();
            $table->string('article_title', 512)->default('');
            $table->string('article_url', 1024)->nullable();

            // « ok » : aucun problème n'a jamais été détecté.
            // « fixed » : des problèmes existaient et ont été clôturés.
            $table->string('status', 24);
            $table->boolean('resolved_manually')->default(false);
            $table->unsignedInteger('issues_resolved')->default(0);

            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['user_id', 'recorded_at']);
            $table->index(['wordpress_site_id', 'status']);
            $table->index(['user_id', 'status', 'recorded_at'], 'ash_user_status_date_idx');
        });

        $this->backfill();
    }

    /**
     * Reprise de l'existant : sans elle, un site déjà audité n'entrerait dans
     * les statistiques qu'au prochain changement de statut.
     *
     * Le SQL est écrit ici plutôt que délégué à un service : une migration est
     * un instantané du schéma tel qu'il était, et doit rester exécutable même
     * si le code applicatif change ensuite — l'agent, par exemple, n'est ajouté
     * que par la migration suivante.
     *
     * La même opération, à jour, reste disponible via `php artisan stats:sync`.
     */
    protected function backfill(): void
    {
        if (! Schema::hasTable('wordpress_articles') || ! Schema::hasTable('wordpress_sites')) {
            return;
        }

        DB::table('wordpress_articles as a')
            ->join('wordpress_sites as s', 's.id', '=', 'a.wordpress_site_id')
            ->whereIn('a.audit_status', ['ok', 'fixed'])
            ->select([
                's.user_id',
                's.id as site_id',
                's.name as site_name',
                's.url as site_url',
                'a.id as article_id',
                'a.wp_id',
                'a.title',
                'a.link',
                'a.audit_status',
                'a.issues_resolved_at',
                'a.status_set_manually_at',
                'a.last_audited_at',
                'a.updated_at',
            ])
            ->orderBy('a.id')
            ->chunk(500, function ($articles) {
                $rows = [];

                foreach ($articles as $article) {
                    $rows[] = [
                        'user_id' => $article->user_id,
                        'wordpress_site_id' => $article->site_id,
                        'site_name' => $article->site_name,
                        'site_url' => $article->site_url,
                        'wordpress_article_id' => $article->article_id,
                        'wp_id' => $article->wp_id,
                        'article_title' => $article->title,
                        'article_url' => $article->link,
                        'status' => $article->audit_status,
                        'resolved_manually' => $article->status_set_manually_at !== null
                            && $article->audit_status === 'fixed',
                        'issues_resolved' => 0,
                        'recorded_at' => $article->issues_resolved_at
                            ?? $article->last_audited_at
                            ?? $article->updated_at
                            ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('article_status_history')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_status_history');
    }
};
