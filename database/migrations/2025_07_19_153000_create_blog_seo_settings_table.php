<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_seo_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->default('Блог');
            $table->text('site_description')->nullable();
            $table->json('site_keywords')->nullable();
            $table->string('default_og_image')->nullable();
            $table->boolean('auto_generate_meta_description')->default(true);
            $table->integer('meta_description_length')->default(160);
            $table->boolean('enable_breadcrumbs')->default(true);
            $table->boolean('enable_structured_data')->default(true);
            $table->boolean('enable_sitemap')->default(true);
            $table->boolean('enable_rss')->default(true);
            $table->string('robots_txt')->nullable();
            $table->json('social_media_links')->nullable();
            $table->string('google_analytics_id')->nullable();
            $table->string('yandex_metrica_id')->nullable();
            $table->string('google_search_console_verification')->nullable();
            $table->string('yandex_webmaster_verification')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_seo_settings');
    }
}; 