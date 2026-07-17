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

        $this->startProcessing($session, $documents);

        return $documents;
    }

    /**
     * @param  Collection<int, EstimateGenerationDocument>  $sourceDocuments
     * @return Collection<int, EstimateGenerationDocument>
     */
    public function reuseDocuments(EstimateGenerationSession $session, Collection $sourceDocuments, User $user): Collection
    {
        $this->documentReconciler->assertMutable($session);
        $existingSourceIds = $session->documents()
            ->get(['meta'])
            ->map(static fn (EstimateGenerationDocument $document): int => (int) ($document->meta['reused_from_document_id'] ?? 0))
            ->filter(static fn (int $documentId): bool => $documentId > 0)
            ->all();
        $documents = collect();

        foreach ($sourceDocuments as $sourceDocument) {
            if (in_array((int) $sourceDocument->id, $existingSourceIds, true)) {
                continue;
            }
            $documents->push($this->storageService->storeReusedDocument($session, $sourceDocument, $user));
        }

        $this->startProcessing($session, $documents);

        return $documents;
    }

    /** @param Collection<int, EstimateGenerationDocument> $documents */
    private function startProcessing(EstimateGenerationSession $session, Collection $documents): void
    {
        if ($documents->isEmpty()) {
            return;
        }

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
}
