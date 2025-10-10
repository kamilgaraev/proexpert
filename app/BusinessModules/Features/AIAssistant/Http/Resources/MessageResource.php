<?php

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'tokens_used' => $this->tokens_used,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

