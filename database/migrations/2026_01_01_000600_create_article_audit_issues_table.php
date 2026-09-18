<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_audit_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_audit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_type', 64);
            $table->string('severity', 16)->default('warning');
            $table->string('message', 512);
            $table->json('metadata')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['wordpress_article_id', 'resolved_at']);
            $table->index(['rule_type', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_audit_issues');
    }
};
