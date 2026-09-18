<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 255);
            $table->string('wp_username')->nullable();
            // Chiffré via le cast "encrypted" du modèle : jamais stocké en clair.
            $table->text('application_password')->nullable();
            $table->string('connection_status', 32)->default('unknown');
            $table->string('connection_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('sync_status', 32)->default('idle');
            $table->string('sync_message')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'url']);
            $table->index(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_sites');
    }
};
