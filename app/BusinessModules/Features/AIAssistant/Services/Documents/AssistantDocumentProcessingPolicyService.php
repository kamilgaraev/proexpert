<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentProcessingPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantExceptionBoundary;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class AssistantDocumentProcessingPolicyService
{
    public const VERSION = 'local_lexical_v1';

    public function __construct(private AuthorizationService $authorization) {}

    public function assign(User $operator, int $organizationId, int $projectId, int $documentId, int $actorId): AssistantDocumentProcessingPolicy
    {
        $this->assertPlatform($operator);
        return DB::transaction(function () use ($operator, $organizationId, $projectId, $documentId, $actorId): AssistantDocumentProcessingPolicy {
            $document = AIAssistantDocument::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->whereKey($documentId)->lockForUpdate()->first();
            $actor = User::query()->whereKey($actorId)->where('is_active', true)->first();
            if ($document === null || $actor === null || $projectId < 1) { $this->deny(); }
            app(AssistantDocumentService::class)->assertProcessingSource($actor, $document);
            return AssistantDocumentProcessingPolicy::query()->updateOrCreate(['document_id' => $documentId], [
                'organization_id' => $organizationId, 'project_id' => $projectId, 'actor_user_id' => $actorId,
                'assigned_by' => $operator->id, 'policy_version' => self::VERSION, 'generation' => (string) Str::uuid(),
                'state' => 'active', 'source_identity' => $this->identity($document)]);
        });
    }

    public function revoke(User $operator, int $organizationId, int $documentId): AssistantDocumentProcessingPolicy
    {
        $this->assertPlatform($operator);
        return DB::transaction(function () use ($operator, $organizationId, $documentId): AssistantDocumentProcessingPolicy {
            $policy = AssistantDocumentProcessingPolicy::query()->where('document_id', $documentId)
                ->where('organization_id', $organizationId)->lockForUpdate()->first();
            if ($policy === null) { $this->deny(); }
            $policy->update(['state' => 'revoked', 'generation' => (string) Str::uuid(), 'assigned_by' => $operator->id]);
            return $policy;
        });
    }

    public function capture(int $documentId): ?AssistantDocumentProcessingPolicy
    {
        $policy = AssistantDocumentProcessingPolicy::query()->where('document_id', $documentId)->first();
        $this->assertCurrent($documentId, $policy);
        return $policy;
    }

    public function selected(int $documentId): bool
    {
        try { return AssistantDocumentProcessingPolicy::query()->where('document_id', $documentId)->exists(); }
        catch (Throwable) { $this->deny(); }
    }

    public function assertExternalAllowed(int $documentId): void
    {
        if ($this->selected($documentId)) { $this->deny(); }
    }

    public function assertCurrent(int $documentId, ?AssistantDocumentProcessingPolicy $captured, bool $lock = false): void
    {
        try { $this->checkCurrent($documentId, $captured, $lock); }
        catch (Throwable) { $this->deny(); }
    }

    private function checkCurrent(int $documentId, ?AssistantDocumentProcessingPolicy $captured, bool $lock): void
    {
        $document = $lock ? AIAssistantDocument::query()->whereKey($documentId)->lockForUpdate()->first() : null;
        $current = AssistantDocumentProcessingPolicy::query()->where('document_id', $documentId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())->first();
        if ($captured === null && $current === null) { return; }
        if ($captured === null || $current === null || $current->state !== 'active' || $current->policy_version !== self::VERSION
            || $current->generation !== $captured->generation || $current->document_id !== $documentId) { $this->deny(); }
        $document ??= AIAssistantDocument::query()->find($documentId);
        $actor = User::query()->whereKey($current->actor_user_id)->where('is_active', true)->first();
        if ($document === null || $actor === null || $document->organization_id !== $current->organization_id
            || $document->project_id !== $current->project_id || $current->project_id < 1
            || ! hash_equals($current->source_identity, $this->identity($document, $lock))) { $this->deny(); }
        app(AssistantDocumentService::class)->assertProcessingSource($actor, $document);
    }

    public function sanitizeFailure(int $documentId, Throwable $failure): Throwable
    {
        try { $selected = $this->selected($documentId); }
        catch (Throwable) { $selected = true; }
        return $selected ? OrdinaryAssistantExceptionBoundary::sanitize($failure) : $failure;
    }

    private function identity(AIAssistantDocument $document, bool $lock = false): string
    {
        $file = $document->file_id === null ? null : File::query()->whereKey($document->file_id)
            ->when($lock, static fn ($query) => $query->lockForUpdate())->first();
        return hash('sha256', json_encode([$document->organization_id, $document->project_id, $document->file_id,
            $document->parent_entity_type, $document->parent_entity_id, $document->storage_path, $document->checksum,
            $file?->updated_at?->toISOString()], JSON_THROW_ON_ERROR));
    }

    private function assertPlatform(User $operator): void
    {
        if (! $operator->is_active || ! User::query()->whereKey($operator->getKey())->where('is_active', true)->exists()
            || ! $this->authorization->canCurrent($operator, OrdinaryAssistantEnrollmentService::PLATFORM_PERMISSION, ['context_type' => 'system'])) { $this->deny(); }
    }

    private function deny(): never
    {
        throw OrdinaryAssistantExceptionBoundary::sanitize(new AssistantEgressDenied('source_stale'));
    }
}
