<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_analyses', function (Blueprint $table) {
            $table->id();
            // sha1 de l'URL : clé courte et déterministe, indexable même pour une URL longue.
            $table->string('url_hash', 40)->unique();
            $table->text('url');
            $table->string('status', 24)->default('pending');
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('mime', 64)->nullable();
            // Variance du Laplacien : plus la valeur est basse, plus l'image est floue.
            $table->float('sharpness')->nullable();
            $table->boolean('is_blurry')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_analyses');
    }
};
