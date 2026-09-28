<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seul l'Admin connecte les sites : un site créé par un agent (quand chaque
 * compte connectait ses propres sites) revient au premier Admin. Ses
 * paramètres d'audit s'appliquent alors, comme pour ses autres sites.
 */
return new class extends Migration
{
    public function up(): void
    {
        $admin = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');

        if ($admin === null) {
            return;
        }

        $agents = DB::table('users')->where('role', '!=', 'admin')->pluck('id');

        DB::table('wordpress_sites')->whereIn('user_id', $agents)->update(['user_id' => $admin]);
    }

    public function down(): void
    {
        // Irréversible sans intérêt : l'ancien créateur reste assigné au site.
    }
};
