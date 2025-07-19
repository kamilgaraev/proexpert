<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('blog_articles')->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('blog_comments')->onDelete('cascade');
            
            $table->string('author_name');
            $table->string('author_email');
            $table->string('author_website')->nullable();
            $table->ipAddress('author_ip');
            $table->text('user_agent')->nullable();
            
            $table->text('content');
            
            $table->enum('status', ['pending', 'approved', 'rejected', 'spam'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('landing_admins')->onDelete('set null');
            
            $table->integer('likes_count')->default(0);
            $table->timestamps();
            
            $table->index(['article_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('parent_id');
            $table->index('author_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_comments');
    }
}; 