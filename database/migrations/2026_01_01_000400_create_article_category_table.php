<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_category', function (Blueprint $table) {
            $table->foreignId('wordpress_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wordpress_category_id')->constrained()->cascadeOnDelete();

            $table->primary(['wordpress_article_id', 'wordpress_category_id'], 'article_category_primary');
            $table->index('wordpress_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_category');
    }
};
