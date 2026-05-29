<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentProcessingStatusService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEstimateGenerationDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation';

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [30, 120];

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $documentId,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function handle(OcrDocumentProcessor $processor): void
    {
        $document = EstimateGenerationDocument::query()
            ->with('session.organization')
            ->find($this->documentId);

        if (!$document instanceof EstimateGenerationDocument) {
            return;
        }

        if (in_array($document->status, ['ready', 'ignored'], true)) {
            return;
        }

        $processor->process($document);
    }

    public function failed(\Throwable $exception): void
    {
        $document = EstimateGenerationDocument::query()->find($this->documentId);

        if ($document instanceof EstimateGenerationDocument) {
            app(DocumentProcessingStatusService::class)->markFailed(
                $document,
                'ocr_job_failed',
                'estimate_generation.ocr_provider_error',
            );
        }

        Log::error('[EstimateGeneration OCR] Document job failed', [
            'document_id' => $this->documentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
