<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Components;

use App\Models\Blog\BlogArticle;
use App\Services\Blog\BlogSeoPreviewService;

final class BlogSeoPreview
{
    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     url: string,
     *     og_title: string,
     *     og_description: string,
     *     og_image: string,
     *     checks: array<int, array{key: string, status: string, message: string}>
     * }
     */
    public static function preview(callable $get, ?BlogArticle $record): array
    {
        return app(BlogSeoPreviewService::class)->preview([
            'title' => $get('title') ?? $record?->title,
            'slug' => $get('slug') ?? $record?->slug,
            'excerpt' => $get('excerpt') ?? $record?->excerpt,
            'canonical_url' => $get('canonical_url') ?? $record?->canonical_url,
            'featured_image' => $get('featured_image') ?? $record?->featured_image,
            'meta_title' => $get('meta_title') ?? $record?->meta_title,
            'meta_description' => $get('meta_description') ?? $record?->meta_description,
            'og_title' => $get('og_title') ?? $record?->og_title,
            'og_description' => $get('og_description') ?? $record?->og_description,
            'og_image' => $get('og_image') ?? $record?->og_image,
            'noindex' => $get('noindex') ?? $record?->noindex,
        ]);
    }
}
