<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DeleteUnpublishedDocumentObject;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentObjectPublicationLock;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UnpublishedDocumentObject;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UnpublishedDocumentObjectBatch;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\Models\User;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OcrDocumentStorageService
{
    public function __construct(
        private readonly FileService $fileService,
    ) {}

    public function storeUploadedDocument(
        EstimateGenerationSession $session,
        UploadedFile $file,
        User $user,
        ?UnpublishedDocumentObjectBatch $batch = null,
        ?UnpublishedDocumentObject $intent = null,
    ): EstimateGenerationDocument {
        $realPath = $file->getRealPath();
        $checksum = is_string($realPath) && $realPath !== ''
            ? @hash_file('sha256', $realPath)
            : false;

        if (! is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            throw new TypedFailureException(FailureCategory::UserActionRequired, 'document_read_failed');
        }
        if ($intent !== null && ($intent->organizationId !== (int) $session->organization_id
            || $intent->projectId !== (int) $session->project_id || $intent->sessionId !== (int) $session->id
            || ! hash_equals($intent->sha256, $checksum) || $intent->sizeBytes !== (int) $file->getSize())) {
            throw new \InvalidArgumentException('document_upload_intent_source_changed');
        }

        $organization = $session->organization()->first();
        if ($organization === null) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_organization_unavailable');
        }
        $directory = sprintf('estimate-generation/sessions/%d/documents', $session->id);
        $prepared = static function (string $path) use ($batch, $checksum, $file): void {
            $batch?->capture($path, $checksum, (int) $file->getSize());
            (new DocumentObjectPublicationLock)->acquire($path);
        };
        $storagePath = $this->fileService->upload(
            $file,
            $directory,
            null,
            'private',
            $organization,
            privacyMode: true,
            onObjectPrepared: $prepared,
            preparedFilename: $intent === null ? null : basename($intent->path),
        );

        if ($storagePath === false) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
        }
        $prepared($storagePath);
        try {
            if ($intent !== null && $storagePath !== $intent->path) {
                throw new TypedFailureException(FailureCategory::Terminal, 'document_storage_integrity_failed');
            }
            try {
                $head = $this->fileService->describeHead($storagePath);
            } catch (Throwable $exception) {
                throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable', previous: $exception);
            }
            if ($head['size'] !== $file->getSize()) {
                throw new TypedFailureException(FailureCategory::Terminal, 'document_storage_integrity_failed');
            }

            return DB::transaction(fn (): EstimateGenerationDocument => EstimateGenerationDocument::create([
                'session_id' => $session->id,
                'organization_id' => $session->organization_id,
                'project_id' => $session->project_id,
                'user_id' => $user->id,
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? $file->getClientMimeType() ?? 'application/octet-stream',
                'storage_path' => $storagePath,
                'status' => 'queued',
                'processing_stage' => 'stored',
                'progress_percent' => 0,
                'file_size_bytes' => $file->getSize(),
                'checksum_sha256' => $checksum,
                'source_version' => 'sha256:'.$checksum,
                'processed_page_count' => 0,
                'ocr_attempts' => 0,
                'structured_payload' => [],
                'meta' => [
                    'original_extension' => $file->getClientOriginalExtension(),
                    'original_name' => $file->getClientOriginalName(),
                    'storage_sha256' => $checksum,
                ],
            ]), 1);
        } catch (Throwable $exception) {
            $this->removeUnpublishedPath($session, $storagePath, $checksum, (int) $file->getSize());
            throw $exception;
        }
    }

    public function storeReusedDocument(
        EstimateGenerationSession $session,
        EstimateGenerationDocument $source,
        User $user,
        ?UnpublishedDocumentObjectBatch $batch = null,
        ?UnpublishedDocumentObject $intent = null,
    ): EstimateGenerationDocument {
        if (
            (int) $source->organization_id !== (int) $session->organization_id
            || (int) $source->project_id !== (int) $session->project_id
            || (int) $source->session_id === (int) $session->id
        ) {
            throw new TypedFailureException(FailureCategory::UserActionRequired, 'document_reuse_source_invalid');
        }

        $sourcePrefix = OrganizationStoragePath::forOrganization(
            (int) $session->organization_id,
            sprintf('estimate-generation/sessions/%d/documents/', $source->session_id),
        );
        if (! str_starts_with((string) $source->storage_path, $sourcePrefix.'/')
            || str_contains((string) $source->storage_path, '\\')
            || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~', (string) $source->storage_path) === 1) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_reuse_storage_path_invalid');
        }

        $sourceMeta = is_array($source->meta) ? $source->meta : [];
        $extension = strtolower((string) ($sourceMeta['original_extension'] ?? pathinfo($source->filename, PATHINFO_EXTENSION)));
        if (preg_match('/^[a-z0-9]{1,12}$/', $extension) !== 1) {
            throw new TypedFailureException(FailureCategory::UserActionRequired, 'document_reuse_extension_invalid');
        }

        if ($intent !== null && ($intent->organizationId !== (int) $session->organization_id
            || $intent->projectId !== (int) $session->project_id || $intent->sessionId !== (int) $session->id
            || ! hash_equals($intent->sha256, (string) $source->checksum_sha256) || $intent->sizeBytes !== (int) $source->file_size_bytes)) {
            throw new \InvalidArgumentException('document_reuse_intent_source_changed');
        }
        $destinationPath = $intent?->path ?? OrganizationStoragePath::forOrganization(
            (int) $session->organization_id,
            sprintf('estimate-generation/sessions/%d/documents/%s.%s', $session->id, Str::uuid(), $extension),
        );
        $batch?->capture($destinationPath, (string) $source->checksum_sha256, (int) $source->file_size_bytes);
        (new DocumentObjectPublicationLock)->acquire($destinationPath);
        try {
            try {
                $copy = $this->fileService->duplicateEstimateGenerationObject((string) $source->storage_path, $destinationPath);
            } catch (Throwable $exception) {
                throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable', previous: $exception);
            }
            if ($copy['path'] !== $destinationPath || (int) $source->file_size_bytes < 1 || $copy['size'] !== (int) $source->file_size_bytes) {
                throw new TypedFailureException(FailureCategory::Terminal, 'document_storage_integrity_failed');
            }
            $verified = $this->fileService->describeCurrent($destinationPath, -(int) $source->file_size_bytes);
            if ($verified['size'] !== (int) $source->file_size_bytes || ! hash_equals((string) $source->checksum_sha256, $verified['sha256'])) {
                throw new TypedFailureException(FailureCategory::Terminal, 'document_storage_integrity_failed');
            }

            return DB::transaction(fn (): EstimateGenerationDocument => EstimateGenerationDocument::create([
                'session_id' => $session->id,
                'organization_id' => $session->organization_id,
                'project_id' => $session->project_id,
                'user_id' => $user->id,
                'filename' => $source->filename,
                'mime_type' => $source->mime_type,
                'storage_path' => $copy['path'],
                'status' => 'queued',
                'processing_stage' => 'stored',
                'progress_percent' => 0,
                'file_size_bytes' => $copy['size'],
                'checksum_sha256' => $source->checksum_sha256,
                'source_version' => $source->source_version,
                'processed_page_count' => 0,
                'ocr_attempts' => 0,
                'structured_payload' => [],
                'meta' => [
                    'original_extension' => $extension,
                    'original_name' => $sourceMeta['original_name'] ?? $source->filename,
                    'storage_sha256' => (string) $source->checksum_sha256,
                    'reused_from_session_id' => (int) $source->session_id,
                    'reused_from_document_id' => (int) $source->id,
                ],
            ]), 1);
        } catch (Throwable $exception) {
            $this->removeUnpublishedPath($session, $destinationPath, (string) $source->checksum_sha256, (int) $source->file_size_bytes);
            throw $exception;
        }
    }

    public function removeUnpublishedDocumentObject(
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document,
    ): bool {
        if ((int) $document->organization_id !== (int) $session->organization_id
            || (int) $document->project_id !== (int) $session->project_id
            || (int) $document->session_id !== (int) $session->id) {
            return false;
        }

        return $this->removeUnpublishedPath($session, (string) $document->storage_path, (string) $document->checksum_sha256, (int) $document->file_size_bytes);
    }

    private function removeUnpublishedPath(EstimateGenerationSession $session, string $path, string $sha256, int $sizeBytes): bool
    {
        $prefix = OrganizationStoragePath::forOrganization(
            (int) $session->organization_id,
            sprintf('estimate-generation/sessions/%d/documents/', $session->id),
        ).'/';
        if (! str_starts_with($path, $prefix)
            || str_contains($path, '\\')
            || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~', $path) === 1
            || str_contains(substr($path, strlen($prefix)), '/')) {
            return false;
        }

        try {
            $object = new UnpublishedDocumentObject((int) $session->organization_id, (int) $session->project_id,
                (int) $session->id, $path, $sha256, $sizeBytes);
            $delete = new DeleteUnpublishedDocumentObject($this->fileService,
                new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\PersistedDocumentObjectReferences,
                new DocumentObjectPublicationLock);

            return $delete->handle($object) === 'completed';
        } catch (Throwable $exception) {
            Log::warning('estimate_generation_document_cleanup_pending', [
                'organization_id' => (int) $session->organization_id,
                'session_id' => (int) $session->id,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }
}
