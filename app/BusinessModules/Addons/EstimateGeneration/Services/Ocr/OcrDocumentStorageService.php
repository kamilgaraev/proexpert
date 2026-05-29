<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use RuntimeException;

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
        $content = file_get_contents((string) $file->getRealPath());

        if ($content === false) {
            throw new RuntimeException('estimate_generation.document_read_error');
        }

        $organization = $session->organization()->first();
        $directory = sprintf('estimate-generation/sessions/%d/documents', $session->id);
        $storagePath = $this->fileService->upload($file, $directory, null, 'private', $organization);

        if ($storagePath === false) {
            throw new RuntimeException('estimate_generation.document_storage_error');
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
            'checksum_sha256' => hash('sha256', $content),
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
