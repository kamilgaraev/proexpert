<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentSettings;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\User;
use App\Services\Storage\FileService;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Files\LegalDocumentFilePolicy;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use Aws\S3\Exception\S3Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AssistantLegalNativeFileAdapter
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy,private readonly AuthorizationService $authorization,private readonly FileService $storage) {}

    public function supports(string $type): bool { return isset(AssistantLegalNativeFileMetadata::definitions()[$type]); }

    public function mapForIndexing(int $organizationId,int $nativeFileId,string $type = 'legal_document_version'): ?File
    {
        if (! $this->supports($type)) { return null; }
        $definition = AssistantLegalNativeFileMetadata::definitions()[$type];
        $row = AssistantLegalNativeFileMetadata::sourceQuery($type,$organizationId)->where('native_source.id',$nativeFileId)
            ->first(array_map(static fn (string $field): string => 'native_source.'.$field,$definition['actors']));
        if ($row === null) { return null; }
        $approved = AssistantDocumentSettings::query()->where('organization_id',$organizationId)->value('approved_by');
        $candidates = array_map(static fn (string $field): int => (int)($row->{$field} ?? 0),$definition['actors']);
        foreach (array_unique(array_filter([...$candidates,(int)$approved])) as $actorId) {
            $actor = User::query()->find($actorId);
            if ($actor === null) { continue; }
            try { $this->assertAccess($actor,$organizationId,$type,$nativeFileId); }
            catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'ai_assistant_document_access_denied') { continue; }
                throw $exception;
            }
            return $this->map($actor,$organizationId,$type,$nativeFileId);
        }
        return null;
    }

    public function map(User $actor,int $organizationId,string $type,string|int $id,?string $requestedPath = null): File
    {
        $this->assertAccess($actor,$organizationId,$type,$id);
        $missingObjectException = null;
        $missingSourcePath = null;
        $missingSourceMorph = null;
        $file = DB::transaction(function () use ($actor,$organizationId,$type,$id,$requestedPath,&$missingObjectException,&$missingSourcePath,&$missingSourceMorph): ?File {
            $this->assertAccess($actor,$organizationId,$type,$id);
            $source = $this->source($organizationId,$type,$id,true);
            AssistantLegalNativeFileMetadata::assertSource($type,$source);
            $path = (string)$source[AssistantLegalNativeFileMetadata::definitions()[$type]['path']];
            if ($requestedPath !== null && $requestedPath !== $path) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
            $model = new (AssistantLegalNativeFileMetadata::definitions()[$type]['model']);
            try {
                [$checksum,$size] = $this->checksum($type,$source);
            } catch (S3Exception $exception) {
                if ($exception->getStatusCode() === 404 && $exception->getAwsErrorCode() === 'NoSuchKey') {
                    $missingObjectException = $exception;
                    $missingSourcePath = $path;
                    $missingSourceMorph = $model->getMorphClass();

                    return null;
                }

                throw $exception;
            }
            $this->assertAccess($actor,$organizationId,$type,$id);
            $version = AssistantLegalNativeFileMetadata::versionData($type,$source);
            $fingerprint = AssistantLegalNativeFileMetadata::fingerprint($version);
            $mapping = ['organization_id'=>$organizationId,'fileable_type'=>$model->getMorphClass(),'fileable_id'=>(int)$id,'disk'=>'s3','path'=>$path];
            $file = File::query()->where($mapping)->where('additional_info->assistant_native_source',AssistantLegalNativeFileMetadata::SOURCE)
                ->where('additional_info->native_source_version',$fingerprint)->where('additional_info->native_source_sha256',$checksum)->first();
            if ($file === null) {
                $file = File::withoutEvents(fn (): File => File::query()->create($mapping + ['user_id'=>$actor->id,
                    'name'=>AssistantLegalNativeFileMetadata::filename($type,$source),'original_name'=>AssistantLegalNativeFileMetadata::filename($type,$source),
                    'size'=>$size,'mime_type'=>AssistantLegalNativeFileMetadata::mime($type,$source),'type'=>'document','category'=>'ai_assistant',
                    'additional_info'=>['assistant_native_source'=>AssistantLegalNativeFileMetadata::SOURCE,'native_entity_type'=>$type,
                        'native_source_version'=>$fingerprint,'native_source_sha256'=>$checksum,'native_source_fields'=>$version]]));
            }
            File::query()->where('organization_id',$organizationId)->where('fileable_type',$model->getMorphClass())->where('fileable_id',(int)$id)
                ->where('additional_info->assistant_native_source',AssistantLegalNativeFileMetadata::SOURCE)->whereKeyNot($file->id)
                ->get()->each(static fn (File $obsolete) => $obsolete->deleteQuietly());
            $this->assertMapping($file);
            return $file;
        });

        if ($missingObjectException instanceof S3Exception) {
            if (is_string($missingSourcePath) && is_string($missingSourceMorph)) {
                $this->invalidateMissingMapping($organizationId,$type,$id,$missingSourcePath,$missingSourceMorph);
            }

            throw $missingObjectException;
        }
        if (! $file instanceof File) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }

        return $file;
    }

    private function invalidateMissingMapping(int $organizationId,string $type,string|int $id,string $path,string $morphType): void
    {
        $definition = AssistantLegalNativeFileMetadata::definitions()[$type];
        $fileableTypes = [$definition['model'],$morphType];
        $files = File::query()->where('organization_id',$organizationId)->whereIn('fileable_type',$fileableTypes)
            ->where('fileable_id',(int)$id)->where('disk','s3')->where('path',$path)
            ->where('additional_info->assistant_native_source',AssistantLegalNativeFileMetadata::SOURCE)
            ->where('additional_info->native_entity_type',$type)->get();

        foreach ($files as $file) {
            DB::transaction(function () use ($file,$organizationId,$type,$id,$path): void {
                $documents = AIAssistantDocument::query()->where('organization_id',$organizationId)->where('file_id',$file->id)
                    ->where('parent_entity_type',$type)->where('parent_entity_id',(string)$id)->where('storage_path',$path)->get();
                foreach ($documents as $document) {
                    if ($document->status === AIAssistantDocument::STATUS_FAILED
                        && $document->coverage_status === 'needs_access_review'
                        && $document->last_error === 'native_source_missing') {
                        continue;
                    }

                    $document->update(['status'=>AIAssistantDocument::STATUS_FAILED,'coverage_status'=>'needs_access_review','last_error'=>'native_source_missing']);
                }

                $file->deleteQuietly();
            });
        }
    }

    public function assertMapping(File $file): void
    {
        $type = $file->additional_info['native_entity_type'] ?? null;
        if (! is_string($type) || ! $this->supports($type)) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        $model = new (AssistantLegalNativeFileMetadata::definitions()[$type]['model']);
        $current = File::query()->find($file->id);
        foreach (['organization_id','fileable_type','fileable_id','path','disk','original_name','name','size','mime_type','user_id'] as $field) {
            if ($current === null || (string)$current->getAttribute($field) !== (string)$file->getAttribute($field)) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        }
        foreach (['assistant_native_source','native_entity_type','native_source_version','native_source_sha256'] as $field) {
            if (($current?->additional_info[$field] ?? null) !== ($file->additional_info[$field] ?? null)) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        }
        if ($current === null || $file->trashed() || $current->additional_info != $file->additional_info || $file->disk !== 's3'
            || ($file->additional_info['assistant_native_source'] ?? null) !== AssistantLegalNativeFileMetadata::SOURCE
            || ! in_array($file->fileable_type,[AssistantLegalNativeFileMetadata::definitions()[$type]['model'],$model->getMorphClass()],true)
            || (int)$file->size < 1 || (int)$file->size > AssistantLegalNativeFileMetadata::MAX_BYTES
            || ! preg_match('/^[a-f0-9]{64}$/D',(string)($file->additional_info['native_source_sha256'] ?? ''))) {
            throw new RuntimeException('ai_assistant_document_native_source_invalid');
        }
        $source = $this->source((int)$file->organization_id,$type,$file->fileable_id);
        AssistantLegalNativeFileMetadata::assertSource($type,$source);
        $version = AssistantLegalNativeFileMetadata::versionData($type,$source);
        $matches = true;
        foreach ([$file->additional_info['native_source_fields'] ?? null,$current->additional_info['native_source_fields'] ?? null] as $stored) {
            $matches = $matches && is_array($stored) && count($stored) === count($version);
            foreach ($version as $field=>$value) { $matches = $matches && is_array($stored) && array_key_exists($field,$stored) && $stored[$field] === $value; }
        }
        if (! $matches
            || ($file->additional_info['native_source_version'] ?? null) !== AssistantLegalNativeFileMetadata::fingerprint($version)
            || $file->path !== $source[AssistantLegalNativeFileMetadata::definitions()[$type]['path']]
            || $file->original_name !== AssistantLegalNativeFileMetadata::filename($type,$source) || $file->name !== $file->original_name
            || $file->mime_type !== AssistantLegalNativeFileMetadata::mime($type,$source)
            || ($type === 'legal_document_version' && (int)$file->size !== (int)$source['size_bytes'])) {
            throw new RuntimeException('ai_assistant_document_source_changed');
        }
    }

    public function assertReadable(User $actor,int $organizationId,File $file): void
    {
        $type = $file->additional_info['native_entity_type'] ?? null;
        if ((int)$file->organization_id !== $organizationId || ! is_string($type)) { throw new RuntimeException('ai_assistant_document_access_denied'); }
        $this->assertAccess($actor,$organizationId,$type,$file->fileable_id);
        $this->assertMapping($file);
        [$checksum,$size] = $this->checksum($type,$this->source($organizationId,$type,$file->fileable_id));
        if (! hash_equals((string)$file->additional_info['native_source_sha256'],$checksum) || $size !== (int)$file->size) {
            throw new RuntimeException('ai_assistant_document_checksum_changed');
        }
        $this->assertAccess($actor,$organizationId,$type,$file->fileable_id);
    }

    public function projectId(File $file): ?int
    {
        $this->assertMapping($file);
        $type = (string)$file->additional_info['native_entity_type'];
        $source = $this->source((int)$file->organization_id,$type,$file->fileable_id);
        $project = $source[$type === 'executive_approved_list' ? 'project_id' : 'parent_project_id'] ?? null;
        return $project === null ? null : (int)$project;
    }

    public function constrainMappings(Builder|QueryBuilder $query): void { AssistantLegalNativeFileMetadata::constrainMappings($query); }

    public function canReadNativeContent(User $actor,int $organizationId,string $type,string|int $id): bool
    {
        try { $this->assertAccess($actor,$organizationId,$type,$id); return true; }
        catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'ai_assistant_document_access_denied') { throw $exception; }
            return false;
        }
    }

    private function assertAccess(User $actor,int $organizationId,string $type,string|int $id): void
    {
        if (! $this->supports($type) || ! preg_match('/^[1-9][0-9]*$/D',(string)$id)
            || ! $this->policy->canReadDomain($actor,$organizationId,'assistant') || ! $this->policy->canReadEntity($actor,$organizationId,$type,$id)) {
            throw new RuntimeException('ai_assistant_document_access_denied');
        }
        foreach (AssistantLegalNativeFileMetadata::definitions()[$type]['permissions'] as $permission) {
            if (! $this->authorization->canCurrent($actor,$permission,['organization_id'=>$organizationId])) { throw new RuntimeException('ai_assistant_document_access_denied'); }
        }
        if ($type === 'legal_document_version') {
            $version = LegalArchiveDocumentVersion::query()->where('organization_id',$organizationId)->find($id,
                ['id','organization_id','document_id','document_file_id','processing_status','file_path']);
            if ($version === null) { throw new RuntimeException('ai_assistant_document_access_denied'); }
            try {
                (new LegalDocumentFilePolicy)->assertDownloadAllowed($version,$actor,'preview');
                $document = $version->documentFile?->document;
                if ($document === null) { throw new RuntimeException('ai_assistant_document_access_denied'); }
                (new LegalDocumentAccessService($this->authorization->forCurrentChecks()))->authorize($actor,$document,'view');
            } catch (AuthorizationException $exception) { throw new RuntimeException('ai_assistant_document_access_denied',0,$exception); }
        }
    }

    private function source(int $organizationId,string $type,string|int $id,bool $lock = false): array
    {
        $query = AssistantLegalNativeFileMetadata::sourceQuery($type,$organizationId)->where('native_source.id',$id);
        if ($lock) { $query->lockForUpdate(); }
        $source = $query->first(AssistantLegalNativeFileMetadata::sourceColumns($type));
        if ($source === null) { throw new RuntimeException('ai_assistant_document_native_source_invalid'); }
        return (array)$source;
    }

    private function checksum(string $type,array $source): array
    {
        $definition = AssistantLegalNativeFileMetadata::definitions()[$type];
        $stream = $this->storage->readCurrentBounded((string)$source[$definition['path']],10,AssistantLegalNativeFileMetadata::MAX_BYTES + 1);
        if (! is_resource($stream)) { throw new RuntimeException('ai_assistant_document_native_read_failed'); }
        try { $content = stream_get_contents($stream,AssistantLegalNativeFileMetadata::MAX_BYTES + 1); }
        finally { fclose($stream); }
        if (! is_string($content) || $content === '' || strlen($content) > AssistantLegalNativeFileMetadata::MAX_BYTES
            || ($type === 'legal_document_version' && strlen($content) !== (int)$source['size_bytes'])) {
            throw new RuntimeException('ai_assistant_document_native_size_changed');
        }
        $hash = hash('sha256',$content);
        $expected = $source[$definition['hash']] ?? null;
        if ($expected !== null && ! hash_equals((string)$expected,$hash)) { throw new RuntimeException('ai_assistant_document_checksum_changed'); }
        return [$hash,strlen($content)];
    }
}
