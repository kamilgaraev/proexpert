<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssistantMemoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'content' => $this->payload['content'] ?? '', 'confirmed' => $this->confirmed, 'conversation_id' => $this->conversation_id, 'source_refs' => $this->source_refs ?? [], 'version' => $this->version, 'last_used_at' => $this->last_used_at?->toISOString(), 'expires_at' => $this->expires_at?->toISOString(), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
