<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\AIEstimates\Services\FileProcessing\FileParserService;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class DocumentParsingService
{
    public function __construct(
        protected FileParserService $fileParserService
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, EstimateGenerationDocument>
     */
    public function storeParsedDocuments(EstimateGenerationSession $session, array $files, User $user): Collection
    {
        $parsedFiles = $this->fileParserService->parseFiles($files);
        $documents = collect();

        foreach ($parsedFiles as $index => $parsedFile) {
            $uploadedFile = $files[$index] ?? null;
            $structuredPayload = $parsedFile['structured_data'] ?? $parsedFile['data'] ?? [];
            $extractedText = $parsedFile['text'] ?? json_encode($parsedFile['data'] ?? [], JSON_UNESCAPED_UNICODE);

            $documents->push(EstimateGenerationDocument::create([
                'session_id' => $session->id,
                'organization_id' => $session->organization_id,
                'project_id' => $session->project_id,
                'user_id' => $user->id,
                'filename' => $parsedFile['filename'] ?? $uploadedFile?->getClientOriginalName() ?? 'document',
                'mime_type' => $uploadedFile?->getMimeType() ?? 'application/octet-stream',
                'storage_path' => null,
                'extracted_text' => is_string($extractedText) ? $extractedText : '',
                'structured_payload' => is_array($structuredPayload) ? $structuredPayload : [],
                'meta' => [
                    'type' => $parsedFile['type'] ?? 'unknown',
                    'original_extension' => $uploadedFile?->getClientOriginalExtension(),
                ],
            ]));
        }

        return $documents;
    }
}
