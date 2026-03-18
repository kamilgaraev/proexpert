<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use App\BusinessModules\Features\AIAssistant\Models\Message;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class ConversationResource extends JsonResource
{
    public function toArray($request): array
    {
        $lastMessage = $this->resolveLastMessage();
        $preview = null;

        if ($lastMessage) {
            $preview = mb_strimwidth((string) $lastMessage->content, 0, 140, '...');
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'is_owned_by_current_user' => (int) $this->user_id === (int) optional($request->user())->id,
            'last_message_preview' => $preview,
            'last_message_at' => $lastMessage?->created_at?->toISOString(),
            'messages_count' => $this->whenCounted('messages'),
        ];
    }

    private function resolveLastMessage(): ?Message
    {
        $lastMessage = $this->whenLoaded('lastMessage');

        if ($lastMessage instanceof MissingValue) {
            return null;
        }

        return $lastMessage;
    }
}

