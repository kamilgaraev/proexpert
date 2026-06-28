<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_article_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('knowledge_articles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('surface', 40);
            $table->text('context_key')->nullable();
            $table->string('reaction', 40);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'reaction']);
            $table->index(['surface', 'context_key']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('knowledge_search_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('clicked_article_id')->nullable()->constrained('knowledge_articles')->nullOnDelete();
            $table->string('surface', 40);
            $table->text('query');
            $table->text('module_slug')->nullable();
            $table->text('context_key')->nullable();
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamps();

            $table->index(['surface', 'created_at']);
            $table->index(['clicked_article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_search_events');
        Schema::dropIfExists('knowledge_article_feedback');
    }
};
