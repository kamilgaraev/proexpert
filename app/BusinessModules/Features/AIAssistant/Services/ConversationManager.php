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
        return $this->getMessagesForContextWithBudget($conversation, $limit);
    }

    public function getMessagesForContextWithBudget(
        Conversation $conversation,
        int $limit = 6,
        int $maxTotalChars = 4000,
        int $maxUserMessageChars = 500,
        int $maxAssistantMessageChars = 900
    ): array {
        $messages = $this->getHistory($conversation, $limit)
            ->reverse()
            ->values();

        $prepared = [];
        $usedChars = 0;

        foreach ($messages as $message) {
            $content = $this->buildContextMessageContent($message);
            if ($content === '') {
                continue;
            }

            $remainingChars = $maxTotalChars - $usedChars;
            if ($remainingChars <= 0 && $prepared !== []) {
                break;
            }

            $messageLimit = $message->role === 'assistant'
                ? $maxAssistantMessageChars
                : $maxUserMessageChars;

            $content = $this->truncateText($content, min($messageLimit, max(200, $remainingChars)));
            if ($content === '') {
                continue;
            }

            $prepared[] = [
                'role' => $message->role,
                'content' => $content,
            ];

            $usedChars += mb_strlen($content);
        }

        return array_reverse($prepared);
    }

    public function deleteOldConversations(int $days = 90): int
    {
        $date = now()->subDays($days);

        return Conversation::where('updated_at', '<', $date)->delete();
    }

    public function getConversationsByOrganization(int $organizationId, int $limit = 20): Collection
    {
        return Conversation::forOrganization($organizationId)
            ->with(['lastMessage', 'user'])
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getConversationsByUser(User $user, int $limit = 20): Collection
    {
        return Conversation::forUser($user->id)
            ->with(['lastMessage', 'user'])
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getConversationsByUserInOrganization(User $user, int $organizationId, int $limit = 20): Collection
    {
        return Conversation::forUser($user->id)
            ->forOrganization($organizationId)
            ->with(['lastMessage', 'user'])
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

    public function findOrganizationConversation(int $conversationId, int $organizationId): ?Conversation
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->forOrganization($organizationId)
            ->first();
    }

    private function buildContextMessageContent(Message $message): string
    {
        if ($message->role !== 'assistant') {
            return $this->normalizeText((string) $message->content);
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $parts = [];

        $answer = $metadata['conversation_summary']
            ?? $metadata['answer']
            ?? $message->content;

        if (is_string($answer) && trim($answer) !== '') {
            $parts[] = $this->normalizeText($answer);
        }

        $evidenceSummary = $this->buildEvidenceSummary($metadata['evidence'] ?? null);
        if ($evidenceSummary !== null) {
            $parts[] = $evidenceSummary;
        }

        $limitsSummary = $this->buildStringListSummary($metadata['missing_data'] ?? null, 'Ограничения');
        if ($limitsSummary !== null) {
            $parts[] = $limitsSummary;
        }

        return $this->normalizeText(implode("\n", array_filter($parts)));
    }

    private function buildEvidenceSummary(mixed $evidence): ?string
    {
        if (!is_array($evidence) || $evidence === []) {
            return null;
        }

        $items = [];
        foreach (array_slice($evidence, 0, 3) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = isset($entry['label']) ? $this->normalizeText((string) $entry['label']) : '';
            $value = isset($entry['value']) ? $this->normalizeText((string) $entry['value']) : '';

            if ($label === '' && $value === '') {
                continue;
            }

            $items[] = $label !== '' && $value !== ''
                ? "{$label}: {$value}"
                : ($label !== '' ? $label : $value);
        }

        if ($items === []) {
            return null;
        }

        return 'Основание: ' . implode('; ', $items);
    }

    private function buildStringListSummary(mixed $values, string $title): ?string
    {
        if (!is_array($values) || $values === []) {
            return null;
        }

        $items = array_values(array_filter(array_map(
            fn (mixed $value): string => is_string($value) ? $this->normalizeText($value) : '',
            array_slice($values, 0, 2)
        )));

        if ($items === []) {
            return null;
        }

        return "{$title}: " . implode('; ', $items);
    }

    private function normalizeText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text));

        return is_string($normalized) ? $normalized : trim($text);
    }

    private function truncateText(string $text, int $maxChars): string
    {
        if ($maxChars <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        if ($maxChars <= 3) {
            return mb_substr($text, 0, $maxChars);
        }

        return rtrim(mb_substr($text, 0, $maxChars - 3)) . '...';
    }
}
