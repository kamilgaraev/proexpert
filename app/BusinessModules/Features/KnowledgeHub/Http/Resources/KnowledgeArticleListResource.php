<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Http\Resources;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleKind;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeArticleListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KnowledgeArticleKind|null $kind */
        $kind = $this->kind;
        /** @var KnowledgeArticleStatus|null $status */
        $status = $this->status;

        return [
            'id' => $this->id,
            'kind' => $kind?->value,
            'kind_label' => $kind !== null ? trans_message('knowledge_hub.kinds.'.$kind->value) : null,
            'status' => $status?->value,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'category' => $this->whenLoaded(
                'category',
                fn (): ?KnowledgeCategoryResource => $this->category !== null
                    ? new KnowledgeCategoryResource($this->category)
                    : null,
            ),
            'tags' => $this->tags ?? [],
            'release_version' => $this->release_version,
            'release_date' => $this->release_date?->toDateString(),
            'published_at' => $this->published_at?->toIso8601String(),
            'reading_time' => (int) ($this->reading_time ?? 1),
            'is_featured' => (bool) $this->is_featured,
        ];
    }
}
