<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use App\BusinessModules\Features\AIAssistant\Models\Message;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/**
 * @mixin \App\BusinessModules\Features\AIAssistant\Models\Conversation
 */
class ConversationResource extends JsonResource
{
    public static function collection($resource): ConversationCollection
    {
        return new ConversationCollection($resource);
    }

    public function toArray($request): array
    {
        $lastMessage = $this->resolveLastMessage();
        $preview = null;
        $owned = (int) $this->user_id === (int) optional($request->user())->id;
        $participants = $this->resource->relationLoaded('participants') ? $this->participants : collect();
        $role = $owned ? 'editor' : optional($participants->firstWhere('user_id', optional($request->user())->id))->role;

        if ($lastMessage && $request->user() instanceof \App\Models\User && app(\App\BusinessModules\Features\AIAssistant\Services\ConversationManager::class)->canReadMessage($lastMessage, $this->resource, $request->user(), fresh: false)) {
            $preview = mb_strimwidth((string) $lastMessage->content, 0, 140, '...');
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'context_version' => $this->context_version,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'is_owned_by_current_user' => $owned,
            'scope' => $participants->contains(fn ($participant): bool => (int) $participant->user_id !== (int) $this->user_id) ? 'shared' : 'personal',
            'can_edit' => $owned || $role === 'editor',
            'can_write' => $owned || $role === 'editor',
            'can_manage_participants' => $owned,
            'last_message_preview' => $preview,
            'last_message_at' => $lastMessage?->created_at?->toISOString(),
            'messages_count' => $this->whenCounted('messages'),
            'participant_role' => $role,
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
