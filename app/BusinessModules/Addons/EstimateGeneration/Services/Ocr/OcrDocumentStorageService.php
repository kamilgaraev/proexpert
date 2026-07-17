<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\Models\User;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class OcrDocumentStorageService
{
    public function __construct(
        private readonly FileService $fileService,
    ) {}

    public function storeUploadedDocument(
        EstimateGenerationSession $session,
        UploadedFile $file,
        User $user
    ): EstimateGenerationDocument {
        $realPath = $file->getRealPath();
        $checksum = is_string($realPath) && $realPath !== ''
            ? @hash_file('sha256', $realPath)
            : false;

        if (! is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            throw new TypedFailureException(FailureCategory::UserActionRequired, 'document_read_failed');
        }

        $organization = $session->organization()->first();
        if ($organization === null) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_organization_unavailable');
        }
        $directory = sprintf('estimate-generation/sessions/%d/documents', $session->id);
        $storagePath = $this->fileService->upload(
            $file,
            $directory,
            null,
            'private',
            $organization,
            privacyMode: true,
        );

        if ($storagePath === false) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
        }
        try {
            $head = $this->fileService->describeHead($storagePath);
        } catch (\Throwable $exception) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable', previous: $exception);
        }
        if ($head['size'] !== $file->getSize()) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_storage_integrity_failed');
        }

        return EstimateGenerationDocument::create([
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
                'storage_version_id' => $head['version_id'],
            ],
        ]);
    }

    public function storeReusedDocument(
        EstimateGenerationSession $session,
        EstimateGenerationDocument $source,
        User $user
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
        if (! str_starts_with((string) $source->storage_path, $sourcePrefix)) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_reuse_storage_path_invalid');
        }

        $sourceMeta = is_array($source->meta) ? $source->meta : [];
        $extension = strtolower((string) ($sourceMeta['original_extension'] ?? pathinfo($source->filename, PATHINFO_EXTENSION)));
        if (preg_match('/^[a-z0-9]{1,12}$/', $extension) !== 1) {
            throw new TypedFailureException(FailureCategory::UserActionRequired, 'document_reuse_extension_invalid');
        }

        $destinationPath = OrganizationStoragePath::forOrganization(
            (int) $session->organization_id,
            sprintf('estimate-generation/sessions/%d/documents/%s.%s', $session->id, Str::uuid(), $extension),
        );
        try {
            $copy = $this->fileService->duplicateEstimateGenerationObject((string) $source->storage_path, $destinationPath);
        } catch (\Throwable $exception) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable', previous: $exception);
        }
        if ((int) $source->file_size_bytes < 1 || $copy['size'] !== (int) $source->file_size_bytes) {
            try {
                $this->fileService->removeImmutable($copy['path'], $copy['version_id']);
            } catch (\Throwable) {
            }
            throw new TypedFailureException(FailureCategory::Terminal, 'document_storage_integrity_failed');
        }

        return EstimateGenerationDocument::create([
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
                'storage_version_id' => $copy['version_id'],
                'reused_from_session_id' => (int) $source->session_id,
                'reused_from_document_id' => (int) $source->id,
            ],
        ]);
    }
}
