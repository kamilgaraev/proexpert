<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_article_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('blog_articles')->onDelete('cascade');
            $table->foreignId('tag_id')->constrained('blog_tags')->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['article_id', 'tag_id']);
            $table->index('article_id');
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_article_tag');
    }
}; 