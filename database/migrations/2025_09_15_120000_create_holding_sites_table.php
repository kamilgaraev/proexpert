<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holding_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_group_id')->constrained('organization_groups')->onDelete('cascade');
            $table->string('domain')->unique(); // neostroi.prohelper.pro
            $table->string('title')->default(''); // Заголовок сайта
            $table->text('description')->nullable(); // Описание для SEO
            $table->string('logo_url')->nullable(); // URL логотипа
            $table->string('favicon_url')->nullable(); // URL фавикона
            $table->string('template_id')->default('default'); // ID шаблона
            $table->json('theme_config')->nullable(); // Настройки цветов, шрифтов
            $table->json('seo_meta')->nullable(); // SEO метаданные
            $table->json('analytics_config')->nullable(); // Google Analytics, Яндекс.Метрика
            $table->enum('status', ['draft', 'published', 'maintenance'])->default('draft');
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            $table->index(['domain']);
            $table->index(['status', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holding_sites');
    }
};
