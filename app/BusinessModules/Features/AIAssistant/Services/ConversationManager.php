<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\ConversationParticipant;
use App\BusinessModules\Features\AIAssistant\Models\ConversationSummary;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConversationManager
{
    private readonly AssistantSourceReferenceGuard $references;

    public function __construct(private readonly AssistantDataAccessPolicy $dataAccessPolicy)
    {
        $this->references = new AssistantSourceReferenceGuard($dataAccessPolicy);
    }

    public function createConversation(int $organizationId, User $user, ?string $title = null): Conversation
    {
        $this->assertMember($user, $organizationId);

        return DB::transaction(function () use ($organizationId, $user, $title): Conversation {
            $conversation = Conversation::create(['organization_id' => $organizationId, 'user_id' => $user->id, 'title' => $title, 'context' => [], 'last_activity_at' => now()]);
            ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'role' => 'editor', 'added_by_user_id' => $user->id]);

            return $conversation;
        });
    }

    public function addMessage(Conversation $conversation, string $role, string $content, int $tokens = 0, string $model = 'gpt-6-luna', array $metadata = []): Message
    {
        return DB::transaction(function () use ($conversation, $role, $content, $tokens, $model, $metadata): Message {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $message = $locked->messages()->create(['role' => $role, 'content' => $content, 'tokens_used' => $tokens, 'model' => $model, 'metadata' => $metadata]);
            $message->refresh();
            $locked->forceFill(['last_activity_at' => now(), 'context_version' => (int) $locked->context_version + 1])->save();
            if ($role === 'user' && ! $locked->title) {
                $locked->generateTitle();
            }
            $conversation->setRawAttributes($locked->getAttributes(), true);

            return $message;
        });
    }

    public function updateContext(Conversation $conversation, User $actor, int $organizationId, array $changes, array $remove = [], bool $preserveSelectionDetails = false): Conversation
    {
        $this->assertMember($actor, $organizationId);
        $this->assertAccessible($conversation, $actor, true);

        return DB::transaction(function () use ($conversation, $actor, $organizationId, $changes, $remove, $preserveSelectionDetails): Conversation {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $currentActor = User::query()->findOrFail($actor->id);
            $this->assertMember($currentActor, $organizationId);
            if ((int) $locked->organization_id !== $organizationId || ! $this->canEdit($locked, $currentActor)) {
                throw new RuntimeException('conversation_access_denied');
            }

            $context = is_array($locked->context) ? $locked->context : [];
            if ($preserveSelectionDetails && is_array($changes['selected_estimate'] ?? null)) {
                $current = is_array($context['selected_estimate'] ?? null) ? $context['selected_estimate'] : [];
                if (($changes['selected_estimate']['estimate_id'] ?? null) === ($current['estimate_id'] ?? null)) {
                    $changes['selected_estimate'] = array_merge($current, $changes['selected_estimate']);
                } else {
                    $changes['selected_estimate'] = array_merge(['position_filter' => [], 'position_numbers' => []], $changes['selected_estimate']);
                }
            }
            foreach ($remove as $key) {
                if (is_string($key)) {
                    unset($context[$key]);
                }
            }
            $locked->forceFill([
                'context' => array_merge($context, $changes),
                'context_version' => (int) $locked->context_version + 1,
            ])->save();
            $conversation->setRawAttributes($locked->getAttributes(), true);

            return $conversation;
        });
    }

    public function touchActivity(Conversation $conversation): void
    {
        $conversation->forceFill(['last_activity_at' => now()])->save();
    }

    public function findAccessibleConversation(int $conversationId, User $actor, int $organizationId, bool $write = false): ?Conversation
    {
        $conversation = $this->visibleQuery($actor, $organizationId)->whereKey($conversationId)->first();

        return $conversation && (! $write || $this->canEdit($conversation, $actor)) ? $conversation : null;
    }

    public function queryVisibleConversations(User $actor, int $organizationId): Builder
    {
        return $this->visibleQuery($actor, $organizationId)->with(['user', 'participants', 'lastMessage'])->withCount('messages')->orderByDesc('last_activity_at')->orderByDesc('id');
    }

    public function getHistory(Conversation $conversation, int $limit = 10, ?User $actor = null): Collection
    {
        if (! $actor || ! $this->findAccessibleConversation((int) $conversation->id, $actor, (int) $conversation->organization_id)) {
            return collect();
        }

        $messages = $conversation->messages()->orderByDesc('created_at')->orderByDesc('id')->limit(max(1, min($limit, 100)))->get();

        return $this->visibleHistory($messages, $conversation, $actor);
    }

    public function getHistoryPage(Conversation $conversation, User $actor, int $perPage = 30, int $page = 1): LengthAwarePaginator
    {
        $this->assertAccessible($conversation, $actor);
        $result = $conversation->messages()->orderByDesc('created_at')->orderByDesc('id')->paginate(max(1, min($perPage, 100)), ['*'], 'page', max(1, $page));
        $result->setCollection($this->visibleHistory($result->getCollection(), $conversation, $actor));

        return $result;
    }

    private function visibleHistory(Collection $messages, Conversation $conversation, User $actor): Collection
    {
        return $this->dataAccessPolicy->withCurrentChecks($actor, (int) $conversation->organization_id,
            fn (): Collection => $messages->filter(fn (Message $message): bool => $this->canReadMessage($message, $conversation, $actor, fresh: false))->reverse()->values(),
            fresh: true);
    }

    public function canReadMessage(Message $message, Conversation $conversation, User $actor, bool $fresh = true): bool
    {
        if ((int) $message->conversation_id !== (int) $conversation->id || ! app(AIPermissionChecker::class)->canUseAssistant($actor, (int) $conversation->organization_id, $fresh)) {
            return false;
        }
        if ($message->role !== 'assistant') {
            return true;
        }
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['request_state'] ?? null) === 'pending') {
            return false;
        }
        $refs = $this->messageReferences($metadata);
        if ($refs === null || ! $this->references->canRead($actor, (int) $conversation->organization_id, $refs, $fresh)) {
            return false;
        }

        return (int) $conversation->user_id === (int) $actor->id || ($metadata['validation_status'] ?? null) === 'verified';
    }

    public function getParticipants(Conversation $conversation, ?User $actor = null): Collection
    {
        $actor ??= auth()->user();
        if (! $actor instanceof User || ! $this->findAccessibleConversation((int) $conversation->id, $actor, (int) $conversation->organization_id)) {
            return collect();
        }

        return $conversation->participants()->with('user:id,name')->orderBy('id')->get();
    }

    public function updateParticipants(Conversation $conversation, User $actor, int $organizationId, array $participants): Collection
    {
        $this->assertMember($actor, $organizationId);
        if ((int) $conversation->organization_id !== $organizationId || (int) $conversation->user_id !== (int) $actor->id) {
            throw new RuntimeException('conversation_participants_owner_required');
        }
        foreach ($participants as $item) {
            if (! is_array($item) || ! isset($item['user_id']) || ! in_array($item['role'] ?? null, ['viewer', 'editor'], true)) {
                throw new RuntimeException('conversation_participant_invalid');
            }
        }

        return DB::transaction(function () use ($conversation, $organizationId, $actor, $participants): Collection {
            Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $desired = collect($participants)->keyBy(fn (array $item): int => (int) $item['user_id']);
            $ids = $desired->keys();
            $active = DB::table('organization_user')->where('organization_id', $organizationId)->where('is_active', true)->whereIn('user_id', $ids)->lockForUpdate()->pluck('user_id');
            if ($active->count() !== $ids->count()) {
                throw new RuntimeException('conversation_participant_not_in_organization');
            }
            $desired->put($actor->id, ['user_id' => $actor->id, 'role' => 'editor']);
            $conversation->participants()->whereNotIn('user_id', $desired->keys())->delete();
            foreach ($desired as $item) {
                ConversationParticipant::updateOrCreate(['conversation_id' => $conversation->id, 'user_id' => (int) $item['user_id']], ['role' => $item['role'], 'added_by_user_id' => $actor->id]);
            }

            return $this->getParticipants($conversation, $actor);
        });
    }

    public function getSummary(Conversation $conversation, ?User $actor = null): ?ConversationSummary
    {
        if (! $actor || ! $this->findAccessibleConversation((int) $conversation->id, $actor, (int) $conversation->organization_id)) {
            return null;
        }
        $summary = $conversation->summary()->first();
        if (! $summary || ! $this->references->canRead($actor, (int) $conversation->organization_id, array_merge($summary->source_refs ?? [], $summary->selected_entities ?? []))) {
            return null;
        }
        if (($summary->summary_segments ?? []) === [] || ! $this->references->fresh($actor, (int) $conversation->organization_id, $summary->source_refs ?? [])) {
            $summary = clone $summary;
            $summary->summary = null;
            $summary->summary_segments = [];
            $summary->user_decisions = [];
            $summary->source_refs = [];
        }

        return $summary;
    }

    public function saveSummary(Conversation $conversation, User $actor, array $summary, array $sourceRefs, int $expectedVersion): ConversationSummary
    {
        $this->assertAccessible($conversation, $actor, true);
        if (($summary['validation_status'] ?? null) !== 'verified' || ! $this->references->fresh($actor, (int) $conversation->organization_id, $sourceRefs)) {
            throw new RuntimeException('conversation_summary_access_denied');
        }
        $entities = is_array($summary['selected_entities'] ?? null) ? $summary['selected_entities'] : [];
        if (! $this->references->canRead($actor, (int) $conversation->organization_id, $entities)) {
            throw new RuntimeException('conversation_summary_access_denied');
        }

        return DB::transaction(function () use ($conversation, $actor, $summary, $sourceRefs, $entities, $expectedVersion): ConversationSummary {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ((int) $locked->context_version !== $expectedVersion) {
                throw new RuntimeException('conversation_context_version_conflict');
            }
            $existing = $this->getSummary($locked, $actor);
            $decisions = is_array($summary['user_decisions'] ?? null) ? $summary['user_decisions'] : [];
            $selected = array_slice($this->mergeReferences($existing?->selected_entities ?? [], $entities), -100);
            $segments = array_filter($existing?->summary_segments ?? [], fn (array $segment): bool => $this->references->fresh($actor, (int) $conversation->organization_id, $segment['source_refs'] ?? []));
            $text = $this->text($summary['summary'] ?? null);
            if ($text !== null && $text !== '' && count($sourceRefs) <= 100) {
                $segments[] = ['text' => $text, 'source_refs' => $sourceRefs];
            }
            $bounded = $this->boundedSummary($segments, $selected, $this->mergeReferences($existing?->source_refs ?? [], $sourceRefs));

            return ConversationSummary::updateOrCreate(['conversation_id' => $locked->id], [
                'summary' => $bounded['summary'],
                'summary_segments' => $bounded['segments'],
                'selected_entities' => $selected,
                'user_decisions' => array_slice($this->mergeItems($existing?->user_decisions ?? [], $decisions), -100),
                'source_refs' => $bounded['source_refs'],
                'context_version' => $expectedVersion,
            ]);
        });
    }

    public function getMessagesForContext(Conversation $conversation, int $limit = 10, ?User $actor = null): array
    {
        return $this->getMessagesForContextWithBudget($conversation, $limit, actor: $actor);
    }

    public function getMessagesForContextWithBudget(Conversation $conversation, int $limit = 6, int $maxTotalChars = 4000, int $maxUserMessageChars = 4000, int $maxAssistantMessageChars = 900, ?User $actor = null): array
    {
        $prepared = [];
        $used = 0;
        foreach ($this->getHistory($conversation, $limit, $actor)->reverse() as $message) {
            $content = $this->contextContent($message, $actor, (int) $conversation->organization_id);
            $remaining = $maxTotalChars - $used;
            if ($remaining <= 0) {
                break;
            }
            if ($content === '') {
                continue;
            }
            $content = $this->truncate($content, min($message->role === 'user' ? $maxUserMessageChars : $maxAssistantMessageChars, $remaining));
            $prepared[] = ['role' => $message->role, 'content' => $content];
            $used += mb_strlen($content);
        }

        return array_reverse($prepared);
    }

    public function deleteConversation(Conversation $conversation, User $actor): void
    {
        $this->assertAccessible($conversation, $actor);
        if ((int) $conversation->user_id !== (int) $actor->id) {
            throw new RuntimeException('conversation_owner_required');
        }
        app(AssistantRetentionService::class)->deleteConversation($conversation);
    }

    public function deleteOldConversations(int $days = 90): int
    {
        return app(AssistantRetentionService::class)->purge($days)['conversations'];
    }

    public function getConversationsByOrganization(int $organizationId, int $limit = 20, ?User $actor = null): Collection
    {
        return $actor ? $this->queryVisibleConversations($actor, $organizationId)->limit($limit)->get() : collect();
    }

    public function getConversationsByUser(User $user, int $limit = 20): Collection
    {
        return $this->getConversationsByUserInOrganization($user, (int) $user->current_organization_id, $limit);
    }

    public function getConversationsByUserInOrganization(User $user, int $organizationId, int $limit = 20): Collection
    {
        return $this->queryVisibleConversations($user, $organizationId)->limit($limit)->get();
    }

    public function findUserConversation(int $conversationId, User $user, int $organizationId): ?Conversation
    {
        return $this->findAccessibleConversation($conversationId, $user, $organizationId);
    }

    public function findOrganizationConversation(int $conversationId, int $organizationId, ?User $actor = null): ?Conversation
    {
        return $actor ? $this->findAccessibleConversation($conversationId, $actor, $organizationId) : null;
    }

    private function visibleQuery(User $actor, int $organizationId): Builder
    {
        $query = Conversation::query()->forOrganization($organizationId);
        if (! $actor->is_active || (int) $actor->current_organization_id !== $organizationId || ! $actor->belongsToOrganization($organizationId) || ! app(AIPermissionChecker::class)->canUseAssistant($actor, $organizationId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->where('user_id', $actor->id)->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $actor->id)));
    }

    private function canEdit(Conversation $conversation, User $actor): bool
    {
        return (int) $conversation->user_id === (int) $actor->id || $conversation->participants()->where('user_id', $actor->id)->where('role', 'editor')->exists();
    }

    private function assertMember(User $actor, int $organizationId): void
    {
        if ($organizationId < 1 || ! $actor->is_active || (int) $actor->current_organization_id !== $organizationId || ! $actor->belongsToOrganization($organizationId) || ! app(AIPermissionChecker::class)->canUseAssistant($actor, $organizationId)) {
            throw new RuntimeException('conversation_organization_access_denied');
        }
    }

    private function assertAccessible(Conversation $conversation, User $actor, bool $write = false): void
    {
        if (! $this->findAccessibleConversation((int) $conversation->id, $actor, (int) $conversation->organization_id, $write)) {
            throw new RuntimeException('conversation_access_denied');
        }
    }

    private function messageReferences(array $metadata): ?array
    {
        $refs = $metadata['source_refs'] ?? [];
        if (! is_array($refs)) {
            return null;
        }
        foreach ([$metadata['entity_references'] ?? [], $metadata['rag_context']['sources'] ?? []] as $additional) {
            if (! is_array($additional)) {
                return null;
            }
            $refs = array_merge($refs, $additional);
        }

        return $refs;
    }

    private function contextContent(Message $message, ?User $actor, int $organizationId): string
    {
        if ($message->role === 'user') {
            return $this->normalize((string) $message->content);
        }
        $meta = is_array($message->metadata) ? $message->metadata : [];
        if (($meta['request_state'] ?? null) === 'pending') {
            return '';
        }
        $refs = $this->messageReferences($meta);
        if ($message->role !== 'assistant' || ! $actor || ($meta['validation_status'] ?? null) !== 'verified' || $refs === null || $refs === [] || ! $this->references->fresh($actor, $organizationId, $refs)) {
            return '';
        }

        return $this->normalize((string) ($meta['conversation_summary'] ?? $meta['answer'] ?? $message->content));
    }

    private function mergeItems(array $old, array $new): array
    {
        $result = [];
        foreach (array_merge($old, $new) as $item) {
            $key = json_encode($item, JSON_THROW_ON_ERROR);
            unset($result[$key]);
            $result[$key] = $item;
        }

        return array_values($result);
    }

    private function mergeReferences(array $old, array $new): array
    {
        $result = [];
        foreach (array_merge($old, $new) as $item) {
            $key = $this->referenceKey($item);
            unset($result[$key]);
            $result[$key] = $item;
        }

        return array_values($result);
    }

    private function boundedSummary(array $segments, array $selected, array $availableRefs): array
    {
        $bounded = [];
        $remaining = 16000;
        foreach (array_reverse(array_slice($segments, -100)) as $segment) {
            $space = $remaining - ($bounded === [] ? 0 : 1);
            if ($space <= 0) {
                break;
            }
            $text = (string) ($segment['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $segment['text'] = mb_substr($text, max(0, mb_strlen($text) - $space));
            $remaining = $space - mb_strlen($segment['text']);
            $bounded[] = $segment;
        }
        $bounded = array_reverse($bounded);
        $selectedKeys = [];
        foreach ($selected as $entity) {
            $selectedKeys[AssistantSourceReferenceIdentity::entityKey($entity)] = true;
        }
        $selectedRefs = array_values(array_filter($availableRefs, fn (array $ref): bool => isset($selectedKeys[AssistantSourceReferenceIdentity::entityKey($ref)])));
        do {
            $refs = $selectedRefs;
            foreach ($bounded as $segment) {
                $refs = $this->mergeReferences($refs, $segment['source_refs'] ?? []);
            }
            if (count($refs) <= 100) {
                break;
            }
            array_shift($bounded);
        } while ($bounded !== []);
        if ($bounded === []) {
            $refs = $selectedRefs;
        }

        return ['summary' => implode("\n", array_column($bounded, 'text')), 'segments' => array_values($bounded), 'source_refs' => $refs];
    }

    private function referenceKey(array $reference): string
    {
        return AssistantSourceReferenceIdentity::key($reference);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? $this->normalize($value) : null;
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim($text)));
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, max(0, $max - 3))).'...';
    }
}
