<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantNativeFileAdapter
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy,
        private readonly AuthorizationService $authorization, private readonly FileService $storage) {}

    public function supports(string $parentType): bool { return $parentType === AssistantNativeFileMetadata::ENTITY_TYPE; }

    public function mapForIndexing(int $organizationId, int $nativeFileId): ?File
    {
        $source = DB::table('workforce_export_package_files as native_file')
            ->join('workforce_export_packages as package', 'package.id', '=', 'native_file.export_package_id')
            ->where('native_file.organization_id', $organizationId)->where('package.organization_id', $organizationId)
            ->where('native_file.id', $nativeFileId)->first(['package.created_by_user_id']);
        if ($source === null) { return null; }
        $approvedId = AssistantDocumentSettings::query()->where('organization_id', $organizationId)->value('approved_by');
        foreach (array_unique(array_filter([(int) $source->created_by_user_id, (int) $approvedId])) as $actorId) {
            $actor = User::query()->find($actorId);
            if ($actor === null) { continue; }
            try { $this->assertAccess($actor, $organizationId, AssistantNativeFileMetadata::ENTITY_TYPE, $nativeFileId); }
            catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'ai_assistant_document_access_denied') { continue; }
                throw $exception;
            }
            return $this->map($actor, $organizationId, AssistantNativeFileMetadata::ENTITY_TYPE, $nativeFileId);
        }
        return null;
    }

    public function map(User $actor, int $organizationId, string $parentType, string|int $parentId, ?string $requestedPath = null): File
    {
        $this->assertAccess($actor, $organizationId, $parentType, $parentId);
        return DB::transaction(function () use ($actor, $organizationId, $parentType, $parentId, $requestedPath): File {
            $this->assertAccess($actor, $organizationId, $parentType, $parentId);
            [$source, $package, $period] = $this->source($organizationId, $parentId, true);
            AssistantNativeFileMetadata::assertSource($source, $package, $period);
            if ($requestedPath !== null && $requestedPath !== $source['storage_path']) {
                throw new RuntimeException('ai_assistant_document_native_source_invalid');
            }
            $version = AssistantNativeFileMetadata::versionData($source, $package, $period);
            $fingerprint = AssistantNativeFileMetadata::fingerprint($version);
            $checksum = $this->checksum($source);
            $this->assertAccess($actor, $organizationId, $parentType, $parentId);
            $model = new (AssistantNativeFileMetadata::MODEL);
            $mapping = ['organization_id' => $organizationId, 'fileable_type' => $model->getMorphClass(),
                'fileable_id' => (int) $source['id'], 'path' => $source['storage_path'], 'disk' => 's3'];
            $file = File::query()->where($mapping)->where('additional_info->assistant_native_source', AssistantNativeFileMetadata::SOURCE)
                ->where('additional_info->native_source_version', $fingerprint)->where('additional_info->native_source_sha256', $checksum)->first();
            if ($file === null) {
                $file = File::withoutEvents(fn (): File => File::query()->create($mapping + ['user_id' => $actor->id,
                    'name' => $source['file_name'], 'original_name' => $source['file_name'], 'size' => (int) $source['size_bytes'],
                    'mime_type' => AssistantNativeFileMetadata::formats()[$source['file_type']][1], 'type' => 'document', 'category' => 'ai_assistant',
                    'additional_info' => ['assistant_native_source' => AssistantNativeFileMetadata::SOURCE, 'native_entity_type' => $parentType,
                        'native_source_version' => $fingerprint, 'native_source_sha256' => $checksum, 'native_source_fields' => $version]]));
            }
            File::query()->where('organization_id', $organizationId)->where('fileable_type', $model->getMorphClass())
                ->where('fileable_id', (int) $source['id'])->where('additional_info->assistant_native_source', AssistantNativeFileMetadata::SOURCE)
                ->whereKeyNot($file->id)->get()->each(static fn (File $obsolete) => $obsolete->deleteQuietly());
            $this->assertMapping($file);
            return $file;
        });
    }

    public function assertMapping(File $file): void
    {
        $model = new (AssistantNativeFileMetadata::MODEL);
        $currentFile = File::query()->whereKey($file->getKey())->first();
        if ($currentFile === null || $currentFile->additional_info != $file->additional_info
            || (int) $currentFile->organization_id !== (int) $file->organization_id || $currentFile->disk !== $file->disk
            || $currentFile->original_name !== $file->original_name || (int) $currentFile->size !== (int) $file->size || $currentFile->mime_type !== $file->mime_type
            || $currentFile->path !== $file->path || $currentFile->fileable_type !== $file->fileable_type || (int) $currentFile->fileable_id !== (int) $file->fileable_id
            || $file->trashed() || $file->disk !== 's3' || ! in_array((string) $file->fileable_type, [AssistantNativeFileMetadata::MODEL, $model->getMorphClass()], true)
            || ($file->additional_info['assistant_native_source'] ?? null) !== AssistantNativeFileMetadata::SOURCE
            || ($file->additional_info['native_entity_type'] ?? null) !== AssistantNativeFileMetadata::ENTITY_TYPE) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
        [$source, $package, $period] = $this->source((int) $file->organization_id, $file->fileable_id);
        AssistantNativeFileMetadata::assertSource($source, $package, $period);
        $version = AssistantNativeFileMetadata::versionData($source, $package, $period);
        $storedVersion = $file->additional_info['native_source_fields'] ?? null;
        $versionMatches = is_array($storedVersion) && count($storedVersion) === count($version);
        foreach ($version as $field => $value) {
            $versionMatches = $versionMatches && is_array($storedVersion) && array_key_exists($field, $storedVersion) && $storedVersion[$field] === $value;
        }
        if ($file->path !== $source['storage_path'] || $file->original_name !== $source['file_name'] || (int) $file->size !== (int) $source['size_bytes']
            || $file->mime_type !== AssistantNativeFileMetadata::formats()[$source['file_type']][1]
            || ! $versionMatches
            || ($file->additional_info['native_source_version'] ?? null) !== AssistantNativeFileMetadata::fingerprint($version)
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($file->additional_info['native_source_sha256'] ?? ''))) {
            throw new RuntimeException('ai_assistant_document_source_changed');
        }
    }

    public function assertReadable(User $actor, int $organizationId, File $file): void
    {
        if ((int) $file->organization_id !== $organizationId) { throw new RuntimeException('ai_assistant_document_access_denied'); }
        $this->assertAccess($actor, $organizationId, AssistantNativeFileMetadata::ENTITY_TYPE, $file->fileable_id);
        $this->assertMapping($file);
        [$source] = $this->source($organizationId, $file->fileable_id);
        if (! hash_equals((string) $file->additional_info['native_source_sha256'], $this->checksum($source))) {
            throw new RuntimeException('ai_assistant_document_checksum_changed');
        }
        $this->assertAccess($actor, $organizationId, AssistantNativeFileMetadata::ENTITY_TYPE, $file->fileable_id);
    }

    public function projectId(File $file): ?int
    {
        $this->assertMapping($file);
        [, , $period] = $this->source((int) $file->organization_id, $file->fileable_id);
        return $period['project_id'] === null ? null : (int) $period['project_id'];
    }

    public function constrainMappings(Builder|QueryBuilder $query): void { AssistantNativeFileMetadata::constrainMappings($query); }

    private function assertAccess(User $actor, int $organizationId, string $type, string|int $id): void
    {
        if (! $this->supports($type) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)
            || ! $this->policy->canReadDomain($actor, $organizationId, 'assistant')
            || ! $this->policy->canReadEntityContent($actor, $organizationId, $type, $id)) {
            throw new RuntimeException('ai_assistant_document_access_denied');
        }
        foreach (AssistantNativeFileMetadata::PERMISSIONS as $permission) {
            if (! $this->authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId])) {
                throw new RuntimeException('ai_assistant_document_access_denied');
            }
        }
    }

    private function source(int $organizationId, string|int $id, bool $lock = false): array
    {
        $query = DB::table('workforce_export_package_files')->where('organization_id', $organizationId)->where('id', $id);
        if ($lock) { $query->lockForUpdate(); }
        $source = $query->first(['id', 'organization_id', 'export_package_id', 'file_type', 'file_name', 'storage_disk', 'storage_path', 'size_bytes', 'updated_at']);
        $packageQuery = DB::table('workforce_export_packages')->where('organization_id', $organizationId)->where('id', $source?->export_package_id);
        if ($lock) { $packageQuery->lockForUpdate(); }
        $package = $packageQuery->first(['id', 'organization_id', 'payroll_period_id', 'package_number', 'source_hash']);
        $period = DB::table('workforce_payroll_periods')->where('organization_id', $organizationId)->where('id', $package?->payroll_period_id)->first(['id', 'organization_id', 'project_id']);
        if ($source === null || $package === null || $period === null) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        return [(array) $source, (array) $package, (array) $period];
    }

    private function checksum(array $source): string
    {
        $stream = $this->storage->readCurrentBounded($source['storage_path'], 10, AssistantNativeFileMetadata::MAX_BYTES + 1);
        if (! is_resource($stream)) { throw new RuntimeException('ai_assistant_document_native_read_failed'); }
        try { $content = stream_get_contents($stream, AssistantNativeFileMetadata::MAX_BYTES + 1); }
        finally { fclose($stream); }
        if (! is_string($content) || strlen($content) !== (int) $source['size_bytes'] || strlen($content) > AssistantNativeFileMetadata::MAX_BYTES) {
            throw new RuntimeException('ai_assistant_document_native_size_changed');
        }
        return hash('sha256', $content);
    }
}
