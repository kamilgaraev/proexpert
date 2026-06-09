<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_categories', function (Blueprint $table): void {
            $table->id();
            $table->text('title');
            $table->text('slug')->unique();
            $table->text('description')->nullable();
            $table->text('icon')->nullable();
            $table->text('color')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('knowledge_categories')->nullOnDelete();
            $table->string('kind', 40);
            $table->string('status', 40);
            $table->text('title');
            $table->text('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->jsonb('tags')->nullable();
            $table->text('release_version')->nullable();
            $table->date('release_date')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedSmallInteger('reading_time')->default(1);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by_system_admin_id')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->foreignId('updated_by_system_admin_id')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'status', 'published_at']);
            $table->index(['is_featured', 'sort_order']);
            $table->index('release_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_articles');
        Schema::dropIfExists('knowledge_categories');
    }
};
