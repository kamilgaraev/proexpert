<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

class ConversationManager
{
    public function createConversation(int $organizationId, User $user, ?string $title = null): Conversation
    {
        return Conversation::create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'title' => $title,
            'context' => [],
        ]);
    }

    public function addMessage(
        Conversation $conversation,
        string $role,
        string $content,
        int $tokens = 0,
        string $model = 'gpt-4o-mini',
        array $metadata = []
    ): Message {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => $role,
            'content' => $content,
            'tokens_used' => $tokens,
            'model' => $model,
            'metadata' => $metadata,
        ]);

        if (!$conversation->title && $role === 'user') {
            $conversation->generateTitle();
        }

        return $message;
    }

    public function getHistory(Conversation $conversation, int $limit = 10): Collection
    {
        return $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    public function getMessagesForContext(Conversation $conversation, int $limit = 10): array
    {
        $messages = $this->getHistory($conversation, $limit);

        return $messages->map(static function (Message $message): array {
            return [
                'role' => $message->role,
                'content' => $message->content,
            ];
        })->toArray();
    }

    public function deleteOldConversations(int $days = 90): int
    {
        $date = now()->subDays($days);

        return Conversation::where('updated_at', '<', $date)->delete();
    }

    public function getConversationsByOrganization(int $organizationId, int $limit = 20): Collection
    {
        return Conversation::forOrganization($organizationId)
            ->with('lastMessage')
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getConversationsByUser(User $user, int $limit = 20): Collection
    {
        return Conversation::forUser($user->id)
            ->with('lastMessage')
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getConversationsByUserInOrganization(User $user, int $organizationId, int $limit = 20): Collection
    {
        return Conversation::forUser($user->id)
            ->forOrganization($organizationId)
            ->with('lastMessage')
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function findUserConversation(int $conversationId, User $user, int $organizationId): ?Conversation
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->forUser($user->id)
            ->forOrganization($organizationId)
            ->first();
    }
}
