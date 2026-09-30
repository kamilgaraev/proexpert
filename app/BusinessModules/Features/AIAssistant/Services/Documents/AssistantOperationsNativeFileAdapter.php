<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseItemGallery;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantOperationsNativeFileAdapter
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy, private readonly AuthorizationService $authorization, private readonly FileService $storage) {}
    public function supports(string $type): bool { return isset(AssistantOperationsNativeFileMetadata::definitions()[$type]); }

    public function mapForIndexing(int $organizationId, string|int $id, string $type): ?AIAssistantDocument
    {
        if (! $this->supports($type)) { return null; }
        $source = $this->source($organizationId, $type, $id);
        AssistantOperationsNativeFileMetadata::assertSource($type, $source);
        $approved = AssistantDocumentSettings::query()->where('organization_id', $organizationId)->value('approved_by');
        foreach (array_unique(array_filter([(int) ($source['actor_user_id'] ?? 0), (int) $approved])) as $actorId) {
            $actor = User::query()->find($actorId);
            if ($actor === null) { continue; }
            try { $this->assertAccess($actor, $organizationId, $type, $source); }
            catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'ai_assistant_document_access_denied') { continue; }
                throw $exception;
            }
            return $this->mapSource($actor, $organizationId, $type, $source);
        }
        return null;
    }

    public function map(User $actor, int $organizationId, string $type, string|int $id, ?string $requestedPath = null): AIAssistantDocument
    {
        if (! $this->supports($type)) { throw new RuntimeException('ai_assistant_document_access_denied'); }
        $this->assertIdentifier($id);
        if ($type === 'warehouse_item_gallery') {
            $query = $this->accessibleSources($actor, $organizationId, $type, $this->policy)->where('native_parent.id', $id);
            if ($requestedPath !== null) { $query->where('native_source.path', $requestedPath); }
            $rows = $query->limit(2)->get(AssistantOperationsNativeFileMetadata::sourceColumns($type));
            if ($rows->isEmpty()) { throw new RuntimeException('ai_assistant_document_access_denied'); }
            if ($rows->count() !== 1) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
            $source = (array) $rows->first();
        } else { $source = $this->sourceForActor($actor, $organizationId, $type, $id); }
        $this->assertAccess($actor, $organizationId, $type, $source);
        if ($requestedPath !== null && $requestedPath !== $source['storage_path']) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        return $this->mapSource($actor, $organizationId, $type, $source);
    }

    private function mapSource(User $actor, int $organizationId, string $type, array $source): AIAssistantDocument
    {
        $this->assertAccess($actor, $organizationId, $type, $source);
        return DB::transaction(function () use ($actor, $organizationId, $type, $source): AIAssistantDocument {
            $source = $this->source($organizationId, $type, $source['id'], true);
            $this->assertAccess($actor, $organizationId, $type, $source);
            $content = $this->read($type, $source);
            $this->assertAccess($actor, $organizationId, $type, $source);
            $version = AssistantOperationsNativeFileMetadata::versionData($type, $source);
            $checksum = hash('sha256', $content);
            $fingerprint = AssistantOperationsNativeFileMetadata::fingerprint($version);
            $document = AIAssistantDocument::query()->where('organization_id', $organizationId)->whereNull('file_id')
                ->where('metadata->assistant_native_source', AssistantOperationsNativeFileMetadata::SOURCE)->where('metadata->native_source_type', $type)
                ->where('metadata->native_source_id', (string) $source['id'])->where('metadata->native_source_version', $fingerprint)->where('checksum', $checksum)->first();
            $metadata = ['assistant_native_source' => AssistantOperationsNativeFileMetadata::SOURCE, 'native_source_type' => $type,
                'native_source_id' => (string) $source['id'], 'native_parent_id' => (string) $source['native_parent_id'],
                'native_file_id' => $source['native_file_id'] === null ? null : (string) $source['native_file_id'], 'native_source_version' => $fingerprint,
                'native_source_sha256' => $checksum, 'native_actor_user_id' => $actor->id, 'native_source_fields' => $version];
            if ($document === null) {
                foreach (AIAssistantDocument::query()->where('organization_id', $organizationId)->where('metadata->assistant_native_source', AssistantOperationsNativeFileMetadata::SOURCE)
                    ->where('metadata->native_source_type', $type)->where('metadata->native_source_id', (string) $source['id'])->lazyById(50) as $old) {
                    $old->update(['status' => AIAssistantDocument::STATUS_FAILED, 'coverage_status' => 'stale', 'last_error' => 'native_source_changed']);
                    app(RagIndexingCoordinator::class)->queueEntity($organizationId, $old->project_id, 'file_document', 'assistant_document', $old->id);
                }
                $document = AIAssistantDocument::query()->create(['organization_id' => $organizationId,
                    'project_id' => AssistantOperationsNativeFileMetadata::projectId($source), 'file_id' => null,
                    'parent_entity_type' => $type, 'parent_entity_id' => (string) $source['native_parent_id'], 'storage_path' => $source['storage_path'],
                    'filename' => AssistantOperationsNativeFileMetadata::filename($source), 'mime_type' => $source['mime_type'], 'checksum' => $checksum,
                    'size_bytes' => strlen($content), 'status' => AIAssistantDocument::STATUS_QUEUED, 'coverage_status' => 'pending', 'metadata' => $metadata]);
            } else {
                $updates = ['metadata' => array_replace($document->metadata ?? [], $metadata)];
                if ($document->coverage_status === 'needs_access_review') {
                    $updates += ['status' => AIAssistantDocument::STATUS_QUEUED, 'coverage_status' => 'pending', 'last_error' => null];
                }
                $document->update($updates);
            }
            return $document;
        });
    }

    public function assertCurrent(AIAssistantDocument $document): void
    {
        $actor = User::query()->find((int) ($document->metadata['native_actor_user_id'] ?? 0));
        if ($actor === null) { throw new RuntimeException('ai_assistant_document_access_denied'); }
        $this->assertReadable($actor, (int) $document->organization_id, $document);
    }

    public function assertReadable(User $actor, int $organizationId, AIAssistantDocument $document): void { $this->content($actor, $organizationId, $document); }

    public function content(User $actor, int $organizationId, AIAssistantDocument $document): string
    {
        $type = (string) $document->parent_entity_type;
        $source = $this->sourceForActor($actor, $organizationId, $type, (string) ($document->metadata['native_source_id'] ?? ''));
        $this->assertAccess($actor, $organizationId, $type, $source);
        $this->assertMapping($organizationId, $document, $source);
        $content = $this->read($type, $source);
        if (! hash_equals((string) $document->checksum, hash('sha256', $content)) || strlen($content) !== (int) $document->size_bytes) {
            throw new RuntimeException('ai_assistant_document_checksum_changed');
        }
        $fresh = $this->sourceForActor($actor, $organizationId, $type, $source['id']);
        $this->assertAccess($actor, $organizationId, $type, $fresh);
        $this->assertMapping($organizationId, $document, $fresh);
        return $content;
    }

    public function constrainDocuments(Builder|QueryBuilder $query): void { AssistantOperationsNativeFileMetadata::constrainDocuments($query); }

    public function applyDocumentScope(Builder|QueryBuilder $query, User $actor, int $organizationId, AssistantDataAccessPolicy $policy): void
    {
        $query->where(function (Builder|QueryBuilder $types) use ($actor, $organizationId, $policy): void {
            $types->whereRaw('1 = 0');
            foreach (AssistantOperationsNativeFileMetadata::types() as $type) {
                $native = $this->accessibleSources($actor, $organizationId, $type, $policy);
                $types->orWhere(static fn (Builder|QueryBuilder $branch) => $branch->where('ai_assistant_documents.parent_entity_type', $type)
                    ->whereExists($native->selectRaw('1')->whereRaw("CAST(native_source.id AS TEXT) = ai_assistant_documents.metadata->>'native_source_id'")));
            }
        });
    }

    public function applyFileScope(Builder $query, User $actor, int $organizationId, AssistantDataAccessPolicy $policy): void
    {
        $table = $query->getModel()->getTable();
        $medical = $this->accessibleSources($actor, $organizationId, 'safety_medical_exam', $policy)->select('native_file.id');
        $known = DB::table('safety_medical_exams')->where('organization_id', $organizationId)->whereNotNull('file_id')->select('file_id');
        $historical = DB::table('ai_assistant_documents')->where('organization_id', $organizationId)
            ->where('metadata->assistant_native_source', AssistantOperationsNativeFileMetadata::SOURCE)->where('parent_entity_type', 'safety_medical_exam')
            ->whereRaw("(metadata->>'native_file_id') ~ '^[1-9][0-9]*$'")->selectRaw("CAST(metadata->>'native_file_id' AS BIGINT)");
        $query->where(static fn (Builder $scope) => $scope->where(static fn (Builder $ordinary) => $ordinary->whereNotIn($table.'.id', $known)->whereNotIn($table.'.id', $historical))
            ->orWhereIn($table.'.id', $medical));
        $gallery = $this->accessibleSources($actor, $organizationId, 'warehouse_item_gallery', $policy)->select('native_source.id');
        $query->where(static fn (Builder $scope) => $scope->whereNull($table.'.fileable_type')->orWhere($table.'.fileable_type', '!=', (new WarehouseItemGallery)->getMorphClass())->orWhereIn($table.'.id', $gallery));
    }

    public function assertFileReadable(User $actor, int $organizationId, File $file): void
    {
        $query = File::query()->where('organization_id', $organizationId)->whereKey($file->id);
        $this->applyFileScope($query, $actor, $organizationId, $this->policy);
        if (! $query->exists()) { throw new RuntimeException('ai_assistant_document_access_denied'); }
        foreach (['safety_medical_exam', 'warehouse_item_gallery'] as $type) {
            $native = $this->accessibleSources($actor, $organizationId, $type, $this->policy);
            $native->where($type === 'safety_medical_exam' ? 'native_file.id' : 'native_source.id', $file->id);
            $source = $native->first(AssistantOperationsNativeFileMetadata::sourceColumns($type));
            if ($source !== null) {
                $source = (array) $source;
                if ($source['storage_path'] !== $file->path || (int) $source['size_bytes'] !== (int) $file->size || $source['mime_type'] !== $file->mime_type) {
                    throw new RuntimeException('ai_assistant_document_native_source_invalid');
                }
                $this->read($type, $source);
                $fresh = $this->sourceForActor($actor, $organizationId, $type, $source['id']);
                $this->assertAccess($actor, $organizationId, $type, $fresh);
                if (AssistantOperationsNativeFileMetadata::versionData($type, $source) !== AssistantOperationsNativeFileMetadata::versionData($type, $fresh)) {
                    throw new RuntimeException('ai_assistant_document_native_source_invalid');
                }
                return;
            }
        }
        throw new RuntimeException('ai_assistant_document_access_denied');
    }

    public function isNativeFile(File $file): bool
    {
        if ($file->fileable_type === (new WarehouseItemGallery)->getMorphClass()) { return true; }
        if (DB::table('safety_medical_exams')->where('organization_id', $file->organization_id)->where('file_id', $file->id)->exists()) { return true; }
        return AIAssistantDocument::query()->where('organization_id', $file->organization_id)
            ->where('metadata->assistant_native_source', AssistantOperationsNativeFileMetadata::SOURCE)
            ->where('parent_entity_type', 'safety_medical_exam')->where('metadata->native_file_id', (string) $file->id)->exists();
    }

    public function sourceQueryForActor(User $actor, int $organizationId, string $type): QueryBuilder
    {
        return $this->accessibleSources($actor, $organizationId, $type, $this->policy);
    }

    private function accessibleSources(User $actor, int $organizationId, string $type, AssistantDataAccessPolicy $policy): QueryBuilder
    {
        $query = AssistantOperationsNativeFileMetadata::sourceQuery($type, $organizationId);
        if (! $policy->canReadDomain($actor, $organizationId, 'assistant')) { return $query->whereRaw('1 = 0'); }
        foreach (AssistantOperationsNativeFileMetadata::definitions()[$type]['permissions'] as $permission) {
            if (! $policy->canCurrentPermission($actor, $organizationId, $permission)) { return $query->whereRaw('1 = 0'); }
        }
        if ($type !== 'warehouse_item_gallery') {
            $parent = $policy->entityQuery($actor, $organizationId, $type);
            return $parent === null ? $query->whereRaw('1 = 0') : $query->whereIn('native_source.id', $parent->select($parent->getModel()->getQualifiedKeyName()));
        }
        $warehouse = $policy->entityQuery($actor, $organizationId, 'warehouse');
        if ($warehouse === null) { return $query->whereRaw('1 = 0'); }
        return $query->whereIn('native_parent.warehouse_id', $warehouse->select($warehouse->getModel()->getQualifiedKeyName()));
    }

    private function assertAccess(User $actor, int $organizationId, string $type, array $source): void
    {
        if (! $this->supports($type) || $organizationId < 1 || (int) $source['organization_id'] !== $organizationId
            || ! $this->accessibleSources($actor, $organizationId, $type, $this->policy)->where('native_source.id', $source['id'])->exists()) {
            throw new RuntimeException('ai_assistant_document_access_denied');
        }
    }

    private static function sameNativeSourceFields(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) { return false; }
        ksort($left, SORT_STRING);
        ksort($right, SORT_STRING);
        return $left === $right;
    }

    private function assertMapping(int $organizationId, AIAssistantDocument $document, array $source): void
    {
        $type = (string) $document->parent_entity_type;
        AssistantOperationsNativeFileMetadata::assertSource($type, $source);
        $version = AssistantOperationsNativeFileMetadata::versionData($type, $source);
        $metadata = $document->metadata ?? [];
        $current = $document->exists ? AIAssistantDocument::query()->find($document->id) : null;
        if ($current === null || (int) $document->organization_id !== $organizationId || $document->file_id !== null
            || ($metadata['assistant_native_source'] ?? null) !== AssistantOperationsNativeFileMetadata::SOURCE
            || ($metadata['native_source_type'] ?? null) !== $type || ($metadata['native_source_id'] ?? null) !== (string) $source['id']
            || ($metadata['native_parent_id'] ?? null) !== (string) $source['native_parent_id'] || $document->parent_entity_id !== (string) $source['native_parent_id']
            || ($metadata['native_file_id'] ?? null) !== ($source['native_file_id'] === null ? null : (string) $source['native_file_id'])
            || ! self::sameNativeSourceFields($metadata['native_source_fields'] ?? null, $version) || ($metadata['native_source_version'] ?? null) !== AssistantOperationsNativeFileMetadata::fingerprint($version)
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($metadata['native_source_sha256'] ?? '')) || ($metadata['native_source_sha256'] ?? null) !== $document->checksum
            || $document->storage_path !== $source['storage_path'] || $document->mime_type !== $source['mime_type'] || $document->filename !== AssistantOperationsNativeFileMetadata::filename($source)
            || $document->project_id !== AssistantOperationsNativeFileMetadata::projectId($source) || (int) $document->size_bytes !== (int) $source['size_bytes']) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
        foreach (['organization_id', 'project_id', 'file_id', 'parent_entity_type', 'parent_entity_id', 'storage_path', 'filename', 'mime_type', 'checksum', 'size_bytes'] as $field) {
            if ($current->getAttribute($field) !== $document->getAttribute($field)) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        }
        foreach (['assistant_native_source', 'native_source_type', 'native_source_id', 'native_parent_id', 'native_file_id', 'native_source_version', 'native_source_sha256', 'native_actor_user_id', 'native_source_fields'] as $field) {
            $currentValue = $current->metadata[$field] ?? null;
            $mappedValue = $metadata[$field] ?? null;
            if ($field === 'native_source_fields' ? ! self::sameNativeSourceFields($currentValue, $mappedValue) : $currentValue !== $mappedValue) {
                throw new RuntimeException('ai_assistant_document_native_source_invalid');
            }
        }
    }

    private function source(int $organizationId, string $type, string|int $id, bool $lock = false): array
    {
        $this->assertIdentifier($id);
        $query = AssistantOperationsNativeFileMetadata::sourceQuery($type, $organizationId)->where('native_source.id', $id);
        if ($lock) { $query->lock('FOR UPDATE OF native_source'); }
        $source = $query->first(AssistantOperationsNativeFileMetadata::sourceColumns($type));
        if ($source === null) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        return (array) $source;
    }

    private function sourceForActor(User $actor, int $organizationId, string $type, string|int $id): array
    {
        if (! $this->supports($type)) { throw new RuntimeException('ai_assistant_document_access_denied'); }
        $this->assertIdentifier($id);
        $source = $this->accessibleSources($actor, $organizationId, $type, $this->policy)->where('native_source.id', $id)
            ->first(AssistantOperationsNativeFileMetadata::sourceColumns($type));
        if ($source === null) { throw new RuntimeException('ai_assistant_document_access_denied'); }
        return (array) $source;
    }

    private function assertIdentifier(string|int $id): void
    {
        if (! preg_match('/^[1-9][0-9]*$/D', (string) $id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
    }

    private function read(string $type, array $source): string
    {
        AssistantOperationsNativeFileMetadata::assertSource($type, $source);
        $stream = $this->storage->readCurrentBounded((string) $source['storage_path'], 10, AssistantOperationsNativeFileMetadata::MAX_BYTES + 1);
        if (! is_resource($stream)) { throw new RuntimeException('ai_assistant_document_native_read_failed'); }
        try { $content = stream_get_contents($stream, AssistantOperationsNativeFileMetadata::MAX_BYTES + 1); }
        finally { fclose($stream); }
        if (! is_string($content) || $content === '' || strlen($content) !== (int) $source['size_bytes'] || strlen($content) > AssistantOperationsNativeFileMetadata::MAX_BYTES) {
            throw new RuntimeException('ai_assistant_document_native_size_changed');
        }
        if ($source['expected_sha256'] !== null && ! hash_equals((string) $source['expected_sha256'], hash('sha256', $content))) { throw new RuntimeException('ai_assistant_document_checksum_changed'); }
        return $content;
    }
}
