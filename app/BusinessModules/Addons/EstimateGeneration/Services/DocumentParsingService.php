<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReconcileEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentStorageService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DocumentParsingService
{
    public function __construct(
        protected OcrDocumentStorageService $storageService,
        protected ReconcileEstimateGenerationDocuments $documentReconciler,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, EstimateGenerationDocument>
     */
    public function storeParsedDocuments(EstimateGenerationSession $session, array $files, User $user): Collection
    {
        $this->documentReconciler->assertMutable($session);
        $documents = collect();

        foreach ($files as $file) {
            $document = $this->storageService->storeUploadedDocument($session, $file, $user);
            $documents->push($document);
        }

        if ($documents->isNotEmpty()) {
            $session = $this->documentReconciler->changed($session);

            foreach ($documents as $document) {
                $attemptId = (string) Str::uuid();
                $document->forceFill([
                    'meta' => [
                        ...(is_array($document->meta) ? $document->meta : []),
                        'processing_attempt_id' => $attemptId,
                    ],
                ])->saveQuietly();
                ProcessEstimateGenerationDocumentJob::dispatch(
                    $document->id,
                    FailureExecutionSnapshot::capture(
                        $session,
                        'document_manifest',
                        attemptId: $attemptId,
                        documentId: (int) $document->getKey(),
                        sourceVersion: DocumentSourceVersion::fromDocument($document),
                    ),
                )
                    ->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
                    ->onQueue(ProcessEstimateGenerationDocumentJob::QUEUE)
                    ->afterCommit();
            }
        }

        return $documents;
    }
}
