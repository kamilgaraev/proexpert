<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;

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
            ],
        ]);
    }
}
