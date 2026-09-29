<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantSalesNativeFileAdapter
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy, private readonly AuthorizationService $authorization, private readonly FileService $storage) {}

    public function supports(string $type): bool { return isset(AssistantSalesNativeFileMetadata::definitions()[$type]); }

    public function mapForIndexing(int $organizationId, string|int $id, string $type): ?AIAssistantDocument
    {
        if (! $this->supports($type)) { return null; }
        try { $source = $this->source($organizationId, $type, $id); }
        catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'ai_assistant_document_native_source_invalid') { return null; }
            throw $exception;
        }
        $approved = AssistantDocumentSettings::query()->where('organization_id', $organizationId)->value('approved_by');
        $candidates = [(int) $approved];
        foreach (AssistantSalesNativeFileMetadata::definitions()[$type]['actors'] as $field) { $candidates[] = (int) ($source[$field] ?? 0); }
        foreach (array_unique(array_filter($candidates)) as $actorId) {
            $actor = User::query()->find($actorId);
            if ($actor === null) { continue; }
            try { $this->assertAccess($actor, $organizationId, $type, $id); }
            catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'ai_assistant_document_access_denied') { continue; }
                throw $exception;
            }
            return $this->map($actor, $organizationId, $type, $id);
        }
        return null;
    }

    public function map(User $actor, int $organizationId, string $type, string|int $id, ?string $requestedPath = null): AIAssistantDocument
    {
        $this->assertAccess($actor, $organizationId, $type, $id);
        return DB::transaction(function () use ($actor, $organizationId, $type, $id, $requestedPath): AIAssistantDocument {
            $this->assertAccess($actor, $organizationId, $type, $id);
            $source = $this->source($organizationId, $type, $id, true);
            AssistantSalesNativeFileMetadata::assertSource($type, $source);
            $path = (string) $source[AssistantSalesNativeFileMetadata::definitions()[$type]['path']];
            if ($requestedPath !== null && $requestedPath !== $path) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
            $content = $this->read($type, $source);
            $this->assertAccess($actor, $organizationId, $type, $id);
            $checksum = hash('sha256', $content);
            $version = AssistantSalesNativeFileMetadata::versionData($type, $source);
            $fingerprint = AssistantSalesNativeFileMetadata::fingerprint($version);
            $document = AIAssistantDocument::query()->where('organization_id', $organizationId)->whereNull('file_id')
                ->where('parent_entity_type', $type)->where('parent_entity_id', (string) $id)->where('storage_path', $path)
                ->where('checksum', $checksum)->where('metadata->assistant_native_source', AssistantSalesNativeFileMetadata::SOURCE)
                ->where('metadata->native_source_version', $fingerprint)->first();
            $metadata = ['assistant_native_source' => AssistantSalesNativeFileMetadata::SOURCE, 'native_source_type' => $type,
                'native_source_id' => (string) $id, 'native_source_version' => $fingerprint, 'native_source_sha256' => $checksum,
                'native_actor_user_id' => $actor->id, 'native_source_fields' => $version];
            if ($document === null) {
                $document = AIAssistantDocument::query()->create(['organization_id' => $organizationId,
                    'project_id' => AssistantSalesNativeFileMetadata::projectId($source), 'file_id' => null,
                    'parent_entity_type' => $type, 'parent_entity_id' => (string) $id, 'storage_path' => $path,
                    'filename' => AssistantSalesNativeFileMetadata::filename($type, $source), 'mime_type' => AssistantSalesNativeFileMetadata::mime($type, $source),
                    'checksum' => $checksum, 'size_bytes' => strlen($content), 'status' => AIAssistantDocument::STATUS_QUEUED,
                    'coverage_status' => 'pending', 'metadata' => $metadata]);
            } elseif ((int) ($document->metadata['native_actor_user_id'] ?? 0) !== (int) $actor->id) {
                $document->update(['metadata' => array_replace($document->metadata ?? [], $metadata)]);
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

    public function assertReadable(User $actor, int $organizationId, AIAssistantDocument $document): void
    {
        $this->content($actor, $organizationId, $document);
    }

    public function content(User $actor, int $organizationId, AIAssistantDocument $document): string
    {
        $this->assertAccess($actor, $organizationId, (string) $document->parent_entity_type, (string) $document->parent_entity_id);
        $this->assertMapping($organizationId, $document);
        $source = $this->source($organizationId, (string) $document->parent_entity_type, (string) $document->parent_entity_id);
        $content = $this->read((string) $document->parent_entity_type, $source);
        if (! hash_equals((string) $document->checksum, hash('sha256', $content)) || strlen($content) !== (int) $document->size_bytes) {
            throw new RuntimeException('ai_assistant_document_checksum_changed');
        }
        $this->assertAccess($actor, $organizationId, (string) $document->parent_entity_type, (string) $document->parent_entity_id);
        $this->assertMapping($organizationId, $document);
        return $content;
    }

    public function constrainDocuments(Builder|QueryBuilder $query): void { AssistantSalesNativeFileMetadata::constrainDocuments($query); }

    private function assertAccess(User $actor, int $organizationId, string $type, string|int $id): void
    {
        if (! $this->supports($type) || $organizationId < 1) {
            throw new RuntimeException('ai_assistant_document_access_denied');
        }
        foreach (AssistantSalesNativeFileMetadata::definitions()[$type]['permissions'] as $permission) {
            if (! $this->authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId])) {
                throw new RuntimeException('ai_assistant_document_access_denied');
            }
        }
        if (! $this->policy->canReadDomain($actor, $organizationId, 'assistant')
            || ! $this->policy->canReadEntityContent($actor, $organizationId, $type, $id)) {
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

    private function assertMapping(int $organizationId, AIAssistantDocument $document): void
    {
        $type = (string) $document->parent_entity_type;
        $metadata = $document->metadata ?? [];
        $source = $this->source($organizationId, $type, (string) $document->parent_entity_id);
        AssistantSalesNativeFileMetadata::assertSource($type, $source);
        $version = AssistantSalesNativeFileMetadata::versionData($type, $source);
        $current = $document->exists ? AIAssistantDocument::query()->find($document->id) : null;
        if ($current === null || (int) $document->organization_id !== $organizationId || $document->file_id !== null
            || ($metadata['assistant_native_source'] ?? null) !== AssistantSalesNativeFileMetadata::SOURCE
            || ($metadata['native_source_type'] ?? null) !== $type || ($metadata['native_source_id'] ?? null) !== (string) $document->parent_entity_id
            || ! self::sameNativeSourceFields($metadata['native_source_fields'] ?? null, $version)
            || ($metadata['native_source_version'] ?? null) !== AssistantSalesNativeFileMetadata::fingerprint($version)
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($metadata['native_source_sha256'] ?? ''))
            || ($metadata['native_source_sha256'] ?? null) !== $document->checksum
            || $document->storage_path !== (string) $source[AssistantSalesNativeFileMetadata::definitions()[$type]['path']]
            || $document->filename !== AssistantSalesNativeFileMetadata::filename($type, $source)
            || $document->mime_type !== AssistantSalesNativeFileMetadata::mime($type, $source)
            || $document->project_id !== AssistantSalesNativeFileMetadata::projectId($source)
            || (int) $document->size_bytes < 1 || (int) $document->size_bytes > AssistantSalesNativeFileMetadata::MAX_BYTES) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
        foreach (['organization_id', 'project_id', 'file_id', 'parent_entity_type', 'parent_entity_id', 'storage_path', 'filename', 'mime_type', 'checksum', 'size_bytes'] as $field) {
            if ($current->getAttribute($field) !== $document->getAttribute($field)) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        }
        foreach (['assistant_native_source', 'native_source_type', 'native_source_id', 'native_source_version', 'native_source_sha256', 'native_actor_user_id', 'native_source_fields'] as $field) {
            $currentValue = $current->metadata[$field] ?? null;
            $mappedValue = $metadata[$field] ?? null;
            if ($field === 'native_source_fields' ? ! self::sameNativeSourceFields($currentValue, $mappedValue) : $currentValue !== $mappedValue) {
                throw new RuntimeException('ai_assistant_document_native_source_invalid');
            }
        }
    }

    private function source(int $organizationId, string $type, string|int $id, bool $lock = false): array
    {
        $query = AssistantSalesNativeFileMetadata::sourceQuery($type, $organizationId)->where('native_source.id', $id);
        if ($lock) { $query->lock('FOR UPDATE OF native_source'); }
        $source = $query->first(AssistantSalesNativeFileMetadata::sourceColumns($type));
        if ($source === null) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        return (array) $source;
    }

    private function read(string $type, array $source): string
    {
        AssistantSalesNativeFileMetadata::assertSource($type, $source);
        $record = AssistantSalesNativeFileMetadata::definitions()[$type];
        $stream = $this->storage->readCurrentBounded((string) $source[$record['path']], 10, AssistantSalesNativeFileMetadata::MAX_BYTES + 1);
        if (! is_resource($stream)) { throw new RuntimeException('ai_assistant_document_native_read_failed'); }
        try { $content = stream_get_contents($stream, AssistantSalesNativeFileMetadata::MAX_BYTES + 1); }
        finally { fclose($stream); }
        $size = $record['size'] === null ? null : ($source[$record['size']] ?? null);
        $hash = $record['hash'] === null ? null : ($source[$record['hash']] ?? null);
        if (! is_string($content) || $content === '' || strlen($content) > AssistantSalesNativeFileMetadata::MAX_BYTES
            || ($size !== null && strlen($content) !== (int) $size)) { throw new RuntimeException('ai_assistant_document_native_size_changed'); }
        if ($hash !== null && ! hash_equals((string) $hash, hash('sha256', $content))) { throw new RuntimeException('ai_assistant_document_checksum_changed'); }
        return $content;
    }
}
