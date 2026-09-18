<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Droits réels du compte WordPress utilisé par le site.
 *
 * Un compte authentifié n'est pas forcément un compte autorisé : un rôle
 * « abonné » lit l'API REST sans pouvoir demander `context=edit` ni
 * `status=any`. WordPress répond alors 403/400 et la synchronisation échoue
 * entièrement alors que les articles publiés étaient parfaitement lisibles.
 *
 * `wp_can_edit` mémorise ce constat : null tant qu'il n'a pas été établi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table) {
            if (! Schema::hasColumn('wordpress_sites', 'wp_can_edit')) {
                $table->boolean('wp_can_edit')->nullable()->after('wp_username');
                $table->string('wp_role', 64)->nullable()->after('wp_can_edit');
            }

            // Le message de connexion reprend le détail renvoyé par WordPress :
            // 255 caractères sont vite dépassés, et l'écriture échouait alors
            // en plein test de connexion.
            $table->text('connection_message')->nullable()->change();
            $table->text('sync_message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table) {
            $table->dropColumn(['wp_can_edit', 'wp_role']);
            $table->string('connection_message')->nullable()->change();
            $table->string('sync_message')->nullable()->change();
        });
    }
};
