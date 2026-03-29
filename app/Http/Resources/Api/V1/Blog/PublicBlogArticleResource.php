<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Blog;

use App\Models\Blog\BlogArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BlogArticle
 */
class PublicBlogArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogArticle $article */
        $article = $this->resource;

        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'excerpt' => $article->excerpt,
            'content' => $article->content,
            'featured_image' => $article->featured_image,
            'gallery_images' => $article->gallery_images ?? [],
            'meta_title' => $article->meta_title,
            'meta_description' => $article->meta_description,
            'meta_keywords' => $article->meta_keywords ?? [],
            'og_title' => $article->og_title,
            'og_description' => $article->og_description,
            'og_image' => $article->og_image,
            'structured_data' => $article->structured_data,
            'status' => $article->status?->value ?? $article->status,
            'published_at' => $article->published_at?->toISOString(),
            'scheduled_at' => $article->scheduled_at?->toISOString(),
            'views_count' => $article->views_count,
            'likes_count' => $article->likes_count,
            'comments_count' => $article->comments_count,
            'reading_time' => $article->reading_time,
            'estimated_reading_time' => $article->estimated_reading_time,
            'is_featured' => $article->is_featured,
            'allow_comments' => $article->allow_comments,
            'is_published_in_rss' => $article->is_published_in_rss,
            'noindex' => $article->noindex,
            'sort_order' => $article->sort_order,
            'url' => $article->url,
            'is_published' => $article->is_published,
            'readable_published_at' => $article->readable_published_at,
            'category' => $article->category
                ? new PublicBlogCategoryResource($article->category)
                : [
                    'id' => null,
                    'name' => 'Без категории',
                    'slug' => 'uncategorized',
                    'description' => null,
                    'meta_title' => null,
                    'meta_description' => null,
                    'color' => '#0f172a',
                    'image' => null,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => null,
                    'updated_at' => null,
                ],
            'author' => [
                'id' => $article->systemAuthor?->id ?? $article->author?->id,
                'name' => $article->author_label,
                'email' => $article->author_email,
            ],
            'tags' => PublicBlogTagResource::collection($this->whenLoaded('tags')),
            'created_at' => $article->created_at?->toISOString(),
            'updated_at' => $article->updated_at?->toISOString(),
        ];
    }
}
