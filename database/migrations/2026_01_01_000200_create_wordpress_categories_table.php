<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wp_id');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('parent_wp_id')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamps();

            $table->unique(['wordpress_site_id', 'wp_id']);
            $table->index(['wordpress_site_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_categories');
    }
};
