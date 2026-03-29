<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Blog;

use App\Models\Blog\BlogCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BlogCategory
 */
class PublicBlogCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogCategory $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'meta_title' => $category->meta_title,
            'meta_description' => $category->meta_description,
            'color' => $category->color,
            'image' => $category->image,
            'sort_order' => $category->sort_order,
            'is_active' => $category->is_active,
            'articles_count' => $this->whenCounted('articles', $category->articles_count),
            'published_articles_count' => $this->whenCounted('publishedArticles', $category->published_articles_count),
            'created_at' => $category->created_at?->toISOString(),
            'updated_at' => $category->updated_at?->toISOString(),
        ];
    }
}
