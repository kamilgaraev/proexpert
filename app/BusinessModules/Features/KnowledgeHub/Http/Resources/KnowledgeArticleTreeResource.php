<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Resources;

use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;
use Illuminate\Http\Request;

class KnowledgeArticleTreeResource extends KnowledgeArticleListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        return array_merge($base, [
            'children' => $this->resource instanceof KnowledgeArticle && $this->resource->relationLoaded('children')
                ? KnowledgeArticleTreeResource::collection($this->children)->resolve($request)
                : [],
        ]);
    }
}
