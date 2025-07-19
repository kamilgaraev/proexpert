<?php

namespace App\Http\Resources\Api\V1\Landing\Blog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
                $this->relationLoaded('articles') || isset($this->articles_count),
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