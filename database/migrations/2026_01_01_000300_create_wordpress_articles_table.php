<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wp_id');
            $table->string('title', 512)->default('');
            $table->string('slug', 191)->nullable();
            $table->string('link', 1024)->nullable();
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();
            $table->unsignedBigInteger('featured_media_id')->default(0);
            $table->string('featured_media_url', 1024)->nullable();
            $table->string('featured_media_alt', 512)->nullable();
            $table->string('status', 32)->default('publish');
            $table->unsignedBigInteger('author_wp_id')->nullable();
            $table->timestamp('wordpress_published_at')->nullable();
            $table->timestamp('wordpress_modified_at')->nullable();
            $table->timestamp('synced_at')->nullable();

            // État d'audit dénormalisé : évite de recalculer pour l'affichage du tableau.
            $table->string('audit_status', 24)->default('pending');
            $table->unsignedInteger('issues_count')->default(0);
            $table->timestamp('last_audited_at')->nullable();
            $table->timestamp('issues_resolved_at')->nullable();
            // Empreinte du contenu audité : permet de détecter un audit périmé.
            $table->string('audited_content_hash', 64)->nullable();

            $table->timestamps();

            $table->unique(['wordpress_site_id', 'wp_id']);
            $table->index(['wordpress_site_id', 'audit_status'], 'wp_articles_site_status_idx');
            $table->index(['wordpress_site_id', 'wordpress_published_at'], 'wp_articles_site_published_idx');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_articles');
    }
};
