<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray($request): array
    {
        $lastMessage = $this->whenLoaded('lastMessage');
        $preview = null;

        if ($lastMessage) {
            $preview = mb_strimwidth((string) $lastMessage->content, 0, 140, '...');
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'last_message_preview' => $preview,
            'last_message_at' => $lastMessage?->created_at?->toISOString(),
            'messages_count' => $this->whenCounted('messages'),
        ];
    }
}

