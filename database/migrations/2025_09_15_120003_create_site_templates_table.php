<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key')->unique(); // default, modern, corporate
            $table->string('name'); // Современный, Корпоративный, Классический
            $table->text('description')->nullable();
            $table->string('preview_image')->nullable(); // URL preview
            $table->json('default_blocks'); // Default block structure
            $table->json('theme_options'); // Available customization options  
            $table->json('layout_config'); // Layout configuration
            $table->boolean('is_active')->default(true);
            $table->boolean('is_premium')->default(false);
            $table->string('version')->default('1.0.0');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            $table->index(['is_active', 'is_premium']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_templates');
    }
};
