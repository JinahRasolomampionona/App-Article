<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Espace de travail partagé : rôles Admin / Agent et verrou d'édition.
 *
 * - `users.role` distingue l'Admin (gestion, statistiques globales) de
 *   l'Agent (correction des articles qu'il a pris) ;
 * - l'agent d'un article devient un compte (`assigned_to`) et non plus un nom
 *   libre, et l'assignation est un verrou temporaire (`lock_expires_at`) : un
 *   navigateur fermé ne bloque pas un article indéfiniment ;
 * - `article_assignments` garde la trace de chaque prise en charge — qui, de
 *   quand à quand, et avec quel résultat d'audit ;
 * - l'historique des corrections référence désormais le compte de l'agent,
 *   en conservant son nom recopié pour rester lisible s'il est supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('agent')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });

        // Les comptes propriétaires d'un site administraient déjà l'outil ;
        // à défaut, le plus ancien compte devient Admin pour que personne ne
        // se retrouve exclu de la gestion.
        $owners = DB::table('wordpress_sites')->distinct()->pluck('user_id');

        if ($owners->isNotEmpty()) {
            DB::table('users')->whereIn('id', $owners)->update(['role' => 'admin']);
        } elseif ($first = DB::table('users')->orderBy('id')->value('id')) {
            DB::table('users')->where('id', $first)->update(['role' => 'admin']);
        }

        Schema::table('wordpress_articles', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('audit_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('assigned_to');
            $table->timestamp('lock_expires_at')->nullable()->after('locked_at');

            $table->index(['wordpress_site_id', 'assigned_to', 'lock_expires_at'], 'wp_articles_site_lock_idx');
        });

        Schema::create('article_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_article_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wordpress_site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Recopié : l'historique reste lisible après suppression du compte.
            $table->string('agent_name', 191);
            // Admin ayant attribué l'article à un agent, le cas échéant.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('taken_at');
            $table->timestamp('released_at')->nullable();
            // released · completed · expired · reassigned
            $table->string('release_reason', 24)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('audit_result', 24)->nullable();
            $table->unsignedInteger('issues_remaining')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'taken_at']);
            $table->index(['wordpress_article_id', 'released_at']);
            $table->index(['user_id', 'completed_at']);
        });

        Schema::table('article_status_history', function (Blueprint $table) {
            $table->foreignId('agent_user_id')->nullable()->after('agent')
                ->constrained('users')->nullOnDelete();

            $table->index(['agent_user_id', 'recorded_at'], 'ash_agent_user_date_idx');
        });

        // Les noms d'agents déjà saisis sont rattachés au compte du même nom
        // lorsqu'il existe ; les autres restent visibles comme simple nom.
        foreach (DB::table('users')->get(['id', 'name']) as $user) {
            DB::table('article_status_history')
                ->whereNull('agent_user_id')
                ->whereRaw('lower(agent) = ?', [mb_strtolower(trim($user->name))])
                ->update(['agent_user_id' => $user->id]);
        }

        // L'ancienne assignation par nom n'était pas un verrou : elle n'est
        // pas reprise. Aucun article ne doit se retrouver bloqué au démarrage.
        if (Schema::hasColumn('wordpress_articles', 'agent')) {
            Schema::table('wordpress_articles', function (Blueprint $table) {
                $table->dropIndex('wp_articles_site_agent_idx');
            });

            Schema::table('wordpress_articles', function (Blueprint $table) {
                $table->dropColumn(['agent', 'agent_assigned_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('wordpress_articles', function (Blueprint $table) {
            $table->string('agent', 64)->nullable()->after('audit_status');
            $table->timestamp('agent_assigned_at')->nullable()->after('agent');
            $table->index(['wordpress_site_id', 'agent'], 'wp_articles_site_agent_idx');
        });

        Schema::table('wordpress_articles', function (Blueprint $table) {
            $table->dropIndex('wp_articles_site_lock_idx');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['locked_at', 'lock_expires_at']);
        });

        Schema::table('article_status_history', function (Blueprint $table) {
            $table->dropIndex('ash_agent_user_date_idx');
            $table->dropConstrainedForeignId('agent_user_id');
        });

        Schema::dropIfExists('article_assignments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
