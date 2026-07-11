<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReconcileEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentStorageService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrUsageLogger;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class DocumentParsingService
{
    public function __construct(
        protected OcrDocumentStorageService $storageService,
        protected OcrUsageLogger $usageLogger,
        protected ReconcileEstimateGenerationDocuments $documentReconciler,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, EstimateGenerationDocument>
     */
    public function storeParsedDocuments(EstimateGenerationSession $session, array $files, User $user): Collection
    {
        $documents = collect();

        foreach ($files as $file) {
            $document = $this->storageService->storeUploadedDocument($session, $file, $user);
            $this->usageLogger->queued($document);

            $documents->push($document);
        }

        if ($documents->isNotEmpty()) {
            $this->documentReconciler->changed($session);

            foreach ($documents as $document) {
                ProcessEstimateGenerationDocumentJob::dispatch($document->id)
                    ->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
                    ->onQueue(ProcessEstimateGenerationDocumentJob::QUEUE)
                    ->afterCommit();
            }
        }

        return $documents;
    }
}
