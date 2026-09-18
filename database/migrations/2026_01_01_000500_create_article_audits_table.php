<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_article_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('issues_count')->default(0);
            $table->unsignedInteger('resolved_count')->default(0);
            $table->string('content_hash', 64)->nullable();
            $table->string('trigger_source', 32)->default('manual');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['wordpress_article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_audits');
    }
};
