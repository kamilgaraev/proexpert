<?php

namespace App\Http\Resources\Api\V1\Landing\Blog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'article_id' => $this->article_id,
            'parent_id' => $this->parent_id,
            'author_name' => $this->author_name,
            'author_email' => $this->author_email,
            'author_website' => $this->author_website,
            'content' => $this->content,
            'status' => $this->status,
            'approved_at' => $this->approved_at?->toISOString(),
            'likes_count' => $this->likes_count,
            'is_approved' => $this->is_approved,
            'is_root' => $this->is_root,
            
            'article' => $this->whenLoaded('article', function () {
                return [
                    'id' => $this->article->id,
                    'title' => $this->article->title,
                    'slug' => $this->article->slug,
                ];
            }),
            
            'parent' => new self($this->whenLoaded('parent')),
            'replies' => self::collection($this->whenLoaded('replies')),
            
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                ];
            }),
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
} 