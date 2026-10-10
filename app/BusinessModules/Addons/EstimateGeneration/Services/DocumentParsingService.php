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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

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

        try {
            DB::transaction(function () use ($session, $files, $user, $documents): void {
                foreach ($files as $file) {
                    $documents->push($this->storageService->storeUploadedDocument($session, $file, $user));
                }
                $this->startProcessing($session, $documents);
                foreach ($documents as $document) {
                    $document->refresh();
                }
            }, 1);
        } catch (Throwable $exception) {
            $this->rollbackStoredDocuments($session, $documents);
            throw $exception;
        }

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

        try {
            DB::transaction(function () use ($session, $sourceDocuments, $existingSourceIds, $user, $documents): void {
                foreach ($sourceDocuments as $sourceDocument) {
                    if (in_array((int) $sourceDocument->id, $existingSourceIds, true)) {
                        continue;
                    }
                    $documents->push($this->storageService->storeReusedDocument($session, $sourceDocument, $user));
                }
                $this->startProcessing($session, $documents);
                foreach ($documents as $document) {
                    $document->refresh();
                }
            }, 1);
        } catch (Throwable $exception) {
            $this->rollbackStoredDocuments($session, $documents);
            throw $exception;
        }

        return $documents;
    }

    public function rollbackStoredDocuments(EstimateGenerationSession $session, Collection $documents): void
    {
        foreach ($documents as $document) {
            if ($document instanceof EstimateGenerationDocument) {
                $this->storageService->removeUnpublishedDocumentObject($session, $document);
            }
        }
    }

    /** @param Collection<int, EstimateGenerationDocument> $documents */
    private function startProcessing(EstimateGenerationSession $session, Collection $documents): void
    {
        if ($documents->isEmpty()) {
            return;
        }

        $session = $this->documentReconciler->changed($session);
        $pendingManifests = $session->documents()->where('organization_id', $session->organization_id)
            ->where('project_id', $session->project_id)->whereIn('status', ['uploaded', 'queued', 'processing'])
            ->where('processing_control_status', 'active')
            ->whereDoesntHave('processingUnits')->orderBy('id')->get();

        foreach ($pendingManifests as $document) {
            $storedAttempt = $document->meta['processing_attempt_id'] ?? null;
            $attemptId = is_string($storedAttempt) && Str::isUuid($storedAttempt) ? $storedAttempt : (string) Str::uuid();
            $document->forceFill([
                'meta' => [
                    ...(is_array($document->meta) ? $document->meta : []),
                    'processing_attempt_id' => $attemptId,
                ],
            ])->saveQuietly();
            $documentId = (int) $document->getKey();
            $snapshot = FailureExecutionSnapshot::capture(
                $session,
                'document_manifest',
                attemptId: $attemptId,
                documentId: $documentId,
                sourceVersion: DocumentSourceVersion::fromDocument($document),
            );
            DB::afterCommit(static function () use ($documentId, $snapshot): void {
                ProcessEstimateGenerationDocumentJob::dispatch($documentId, $snapshot)
                    ->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
                    ->onQueue(ProcessEstimateGenerationDocumentJob::QUEUE)
                    ->afterCommit();
            });
        }
    }
}
