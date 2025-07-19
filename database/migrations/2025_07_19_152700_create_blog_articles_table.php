<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('blog_categories')->onDelete('cascade');
            $table->foreignId('author_id')->constrained('landing_admins')->onDelete('cascade');
            
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('featured_image')->nullable();
            $table->json('gallery_images')->nullable();
            
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('meta_keywords')->nullable();
            $table->text('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->json('structured_data')->nullable();
            
            $table->enum('status', ['draft', 'published', 'scheduled', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            
            $table->integer('views_count')->default(0);
            $table->integer('likes_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->integer('reading_time')->nullable();
            
            $table->boolean('is_featured')->default(false);
            $table->boolean('allow_comments')->default(true);
            $table->boolean('is_published_in_rss')->default(true);
            $table->boolean('noindex')->default(false);
            
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index(['status', 'published_at']);
            $table->index(['category_id', 'status']);
            $table->index(['is_featured', 'published_at']);
            $table->index('slug');
            $table->index('author_id');
            $table->fullText(['title', 'content', 'excerpt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_articles');
    }
}; 