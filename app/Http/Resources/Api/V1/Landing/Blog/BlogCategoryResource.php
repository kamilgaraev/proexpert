<?php

namespace App\Http\Resources\Api\V1\Landing\Blog;

use App\Http\Resources\ModelJsonResource;
use App\Models\Blog\BlogCategory;
use Illuminate\Http\Request;

class BlogCategoryResource extends ModelJsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->typedResource(BlogCategory::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'color' => $this->color,
            'image' => $this->image,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'articles_count' => $this->when(
                $category->relationLoaded('articles') || isset($this->articles_count),
                $this->articles_count ?? $this->published_articles_count
            ),
            'published_articles_count' => $this->when(
                isset($this->published_articles_count),
                $this->published_articles_count
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
