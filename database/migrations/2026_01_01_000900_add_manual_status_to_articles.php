<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statut posé à la main depuis le tableau des articles.
 *
 * Le moteur d'audit reste la source de vérité : ces deux colonnes servent à
 * distinguer ce que l'utilisateur a déclaré corrigé de ce qu'un audit a
 * réellement constaté, et à pouvoir revenir en arrière.
 *
 * Les colonnes sont ajoutées sous condition : certaines bases installées avant
 * l'enregistrement de cette migration les possèdent déjà, et `migrate` doit
 * rester exécutable sans intervention manuelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wordpress_articles', 'status_set_manually_at')) {
            Schema::table('wordpress_articles', function (Blueprint $table) {
                $table->timestamp('status_set_manually_at')->nullable()->after('issues_resolved_at');
            });
        }

        if (! Schema::hasColumn('article_audit_issues', 'resolved_manually')) {
            Schema::table('article_audit_issues', function (Blueprint $table) {
                $table->boolean('resolved_manually')->default(false)->after('resolved_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('wordpress_articles', function (Blueprint $table) {
            $table->dropColumn('status_set_manually_at');
        });

        Schema::table('article_audit_issues', function (Blueprint $table) {
            $table->dropColumn('resolved_manually');
        });
    }
};
