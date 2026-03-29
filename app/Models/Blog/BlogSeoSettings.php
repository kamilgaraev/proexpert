<?php

declare(strict_types=1);

namespace App\Models\Blog;

use App\Enums\Blog\BlogContextEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogSeoSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'blog_context',
        'site_name',
        'site_description',
        'site_keywords',
        'default_og_image',
        'auto_generate_meta_description',
        'meta_description_length',
        'enable_breadcrumbs',
        'enable_structured_data',
        'enable_sitemap',
        'enable_rss',
        'robots_txt',
        'social_media_links',
        'google_analytics_id',
        'yandex_metrica_id',
        'google_search_console_verification',
        'yandex_webmaster_verification',
    ];

    protected $casts = [
        'blog_context' => BlogContextEnum::class,
        'site_keywords' => 'array',
        'social_media_links' => 'array',
        'auto_generate_meta_description' => 'boolean',
        'meta_description_length' => 'integer',
        'enable_breadcrumbs' => 'boolean',
        'enable_structured_data' => 'boolean',
        'enable_sitemap' => 'boolean',
        'enable_rss' => 'boolean',
    ];

    public static function getInstance(BlogContextEnum $context = BlogContextEnum::MARKETING): self
    {
        return self::firstOrCreate(
            ['blog_context' => $context->value],
            [
                'site_name' => 'Блог ProHelper',
                'auto_generate_meta_description' => true,
                'meta_description_length' => 160,
                'enable_breadcrumbs' => true,
                'enable_structured_data' => true,
                'enable_sitemap' => true,
                'enable_rss' => true,
            ],
        );
    }
}
