<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\AssistantMemory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AssistantMemoryService
{
    private readonly AssistantSourceReferenceGuard $references;

    public function __construct(AssistantDataAccessPolicy $dataAccessPolicy, private readonly ConversationManager $conversations)
    {
        $this->references = new AssistantSourceReferenceGuard($dataAccessPolicy);
    }

    public function list(User $actor, int $organizationId): Collection
    {
        $this->assertMember($actor, $organizationId);

        return AssistantMemory::query()->where('organization_id', $organizationId)->where('user_id', $actor->id)
            ->where('confirmed', true)->where('expires_at', '>', now())->orderByDesc('created_at')->orderByDesc('id')->get()
            ->filter(fn (AssistantMemory $memory): bool => $this->readable($actor, $memory))->values();
    }

    public function forContext(User $actor, int $organizationId): array
    {
        return DB::transaction(function () use ($actor, $organizationId): array {
            $selected = $this->list($actor, $organizationId)
                ->filter(fn (AssistantMemory $memory): bool => trim((string) ($memory->payload['content'] ?? '')) !== '' && $this->references->fresh($actor, $organizationId, $memory->source_refs ?? []))->take(100);
            if ($selected->isNotEmpty()) {
                AssistantMemory::query()->where('organization_id', $organizationId)->where('user_id', $actor->id)
                    ->where('confirmed', true)->where('expires_at', '>', now())->whereIn('id', $selected->modelKeys())
                    ->update(['last_used_at' => now(), 'expires_at' => now()->addDays(90)]);
            }

            return $selected->map(fn (AssistantMemory $memory): array => ['content' => (string) ($memory->payload['content'] ?? ''), 'source_refs' => $memory->source_refs ?? [], 'confirmed' => true])->values()->all();
        });
    }

    public function create(User $actor, int $organizationId, array $data): AssistantMemory
    {
        $this->assertMember($actor, $organizationId);
        $this->assertConfirmed($data);
        $this->assertPublicReferences($data['source_refs'] ?? []);
        $refs = $data['source_refs'] ?? [];
        if (! is_array($refs) || ! $this->references->canRead($actor, $organizationId, $refs)) {
            throw new RuntimeException('assistant_memory_source_access_denied');
        }
        $conversationId = $data['conversation_id'] ?? null;
        if ($conversationId !== null && ! $this->conversations->findAccessibleConversation((int) $conversationId, $actor, $organizationId)) {
            throw new RuntimeException('assistant_memory_access_denied');
        }

        return AssistantMemory::create([
            'organization_id' => $organizationId, 'user_id' => $actor->id, 'created_by_user_id' => $actor->id,
            'conversation_id' => $conversationId, 'kind' => 'confirmed', 'payload' => ['content' => trim($data['content'])],
            'source_refs' => $refs, 'confirmed' => true, 'last_used_at' => now(), 'expires_at' => now()->addDays(90),
        ]);
    }

    public function update(User $actor, int $organizationId, AssistantMemory $memory, array $data): AssistantMemory
    {
        $this->assertMember($actor, $organizationId);
        $this->assertOwner($actor, $organizationId, $memory);
        $this->assertConfirmed($data);
        if (array_key_exists('source_refs', $data)) {
            $this->assertPublicReferences($data['source_refs']);
        }

        return DB::transaction(function () use ($actor, $organizationId, $memory, $data): AssistantMemory {
            $locked = AssistantMemory::query()->lockForUpdate()->findOrFail($memory->id);
            if (isset($data['version']) && (int) $locked->version !== (int) $data['version']) {
                throw new RuntimeException('assistant_memory_version_conflict');
            }
            if (! $this->readable($actor, $locked)) {
                throw new RuntimeException('assistant_memory_source_access_denied');
            }
            $refs = $data['source_refs'] ?? $locked->source_refs ?? [];
            if (! is_array($refs) || ! $this->references->canRead($actor, $organizationId, $refs)) {
                throw new RuntimeException('assistant_memory_source_access_denied');
            }
            $locked->update(['payload' => ['content' => trim($data['content'])], 'source_refs' => $refs, 'confirmed' => true,
                'version' => (int) $locked->version + 1, 'last_used_at' => now(), 'expires_at' => now()->addDays(90)]);

            return $locked->refresh();
        });
    }

    public function delete(User $actor, int $organizationId, AssistantMemory $memory): void
    {
        $this->assertMember($actor, $organizationId);
        $this->assertOwner($actor, $organizationId, $memory);
        $memory->delete();
    }

    public function purgeExpired(): int
    {
        return AssistantMemory::query()->where('expires_at', '<=', now())->delete();
    }

    private function readable(User $actor, AssistantMemory $memory): bool
    {
        return $this->references->canRead($actor, (int) $memory->organization_id, $memory->source_refs ?? [])
            && ($memory->conversation_id === null || $this->conversations->findAccessibleConversation((int) $memory->conversation_id, $actor, (int) $memory->organization_id) !== null);
    }

    private function assertMember(User $actor, int $organizationId): void
    {
        if ($organizationId < 1 || ! $actor->is_active || (int) $actor->current_organization_id !== $organizationId || ! $actor->belongsToOrganization($organizationId) || ! app(AIPermissionChecker::class)->canUseAssistant($actor, $organizationId)) {
            throw new RuntimeException('assistant_memory_access_denied');
        }
    }

    private function assertOwner(User $actor, int $organizationId, AssistantMemory $memory): void
    {
        if ((int) $memory->user_id !== (int) $actor->id || (int) $memory->organization_id !== $organizationId) {
            throw new RuntimeException('assistant_memory_access_denied');
        }
    }

    private function assertConfirmed(array $data): void
    {
        if (($data['confirmed'] ?? null) !== true || ! is_string($data['content'] ?? null) || trim($data['content']) === '' || mb_strlen($data['content']) > 8000) {
            throw new RuntimeException('assistant_memory_confirmation_required');
        }
    }

    private function assertPublicReferences(mixed $references): void
    {
        $allowed = ['type', 'entity_type', 'id', 'entity_id', 'organization_id', 'fetched_at', 'source_id', 'checksum'];
        if (! is_array($references) || ! array_is_list($references) || count($references) > 100) {
            throw new RuntimeException('assistant_memory_source_access_denied');
        }
        foreach ($references as $reference) {
            if (! is_array($reference) || array_diff(array_keys($reference), $allowed) !== []) {
                throw new RuntimeException('assistant_memory_source_access_denied');
            }
        }
    }
}
