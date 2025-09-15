<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_content_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_site_id')->constrained('holding_sites')->onDelete('cascade');
            $table->string('block_type'); // hero, about, projects, contacts, services, team
            $table->string('block_key')->nullable(); // unique identifier within site
            $table->string('title')->nullable();
            $table->json('content'); // Основной контент блока (JSON для гибкости)
            $table->json('settings')->nullable(); // Настройки отображения блока
            $table->integer('sort_order')->default(0); // Порядок отображения
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            $table->index(['holding_site_id', 'block_type']);
            $table->index(['holding_site_id', 'sort_order']);
            $table->index(['status', 'is_active']);
            $table->unique(['holding_site_id', 'block_key'], 'site_block_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_content_blocks');
    }
};
