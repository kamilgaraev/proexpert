<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeContextHelpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'primary' => $this->resource['primary'] !== null
                ? (new KnowledgeArticleListResource($this->resource['primary']))->resolve($request)
                : null,
            'suggested' => KnowledgeArticleListResource::collection($this->resource['suggested'])->resolve($request),
            'context' => $this->resource['context'],
        ];
    }
}
