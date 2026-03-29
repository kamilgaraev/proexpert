<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Blog;

use App\Models\Blog\BlogTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BlogTag
 */
class PublicBlogTagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BlogTag $tag */
        $tag = $this->resource;

        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
        ];
    }
}
