<?php

namespace App\Http\Resources\Api\V1\Landing\Blog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->content,
            'featured_image' => $this->featured_image,
            'gallery_images' => $this->gallery_images,
            
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'og_image' => $this->og_image,
            'structured_data' => $this->structured_data,
            
            'status' => $this->status,
            'published_at' => $this->published_at?->toISOString(),
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            
            'views_count' => $this->views_count,
            'likes_count' => $this->likes_count,
            'comments_count' => $this->comments_count,
            'reading_time' => $this->reading_time,
            'estimated_reading_time' => $this->estimated_reading_time,
            
            'is_featured' => $this->is_featured,
            'allow_comments' => $this->allow_comments,
            'is_published_in_rss' => $this->is_published_in_rss,
            'noindex' => $this->noindex,
            'sort_order' => $this->sort_order,
            
            'url' => $this->url,
            'is_published' => $this->is_published,
            'readable_published_at' => $this->readable_published_at,
            
            'category' => new BlogCategoryResource($this->whenLoaded('category')),
            'author' => $this->whenLoaded('author', function () {
                return [
                    'id' => $this->author->id,
                    'name' => $this->author->name,
                    'email' => $this->author->email,
                ];
            }),
            'tags' => BlogTagResource::collection($this->whenLoaded('tags')),
            'comments' => BlogCommentResource::collection($this->whenLoaded('comments')),
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
} 