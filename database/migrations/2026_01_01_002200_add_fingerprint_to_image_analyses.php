<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empreinte visuelle des images (dHash) : repère une même photo publiée sous
 * deux fichiers différents (téléversée deux fois, par exemple).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_analyses', function (Blueprint $table) {
            $table->string('fingerprint', 16)->nullable()->after('is_blurry');
        });
    }

    public function down(): void
    {
        Schema::table('image_analyses', function (Blueprint $table) {
            $table->dropColumn('fingerprint');
        });
    }
};
