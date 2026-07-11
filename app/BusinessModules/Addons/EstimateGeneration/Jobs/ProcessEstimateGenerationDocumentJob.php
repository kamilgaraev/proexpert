<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\CreateDocumentProcessingUnits;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentProcessingStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessEstimateGenerationDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation';

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(private readonly int $documentId)
    {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('estimate-generation:document-dispatch:'.$this->documentId))
                ->releaseAfter(15)
                ->expireAfter($this->timeout + 60),
            new RateLimited('estimate-generation-ocr-documents'),
        ];
    }

    public function rateLimitKey(): string
    {
        return 'document:'.$this->documentId;
    }

    public function handle(CreateDocumentProcessingUnits $creator): void
    {
        $document = EstimateGenerationDocument::query()->with('session')->find($this->documentId);

        if (! $document instanceof EstimateGenerationDocument || in_array($document->status, ['ready', 'ignored'], true)) {
            return;
        }

        $creator->handle($document);
    }

    public function failed(\Throwable $error): void
    {
        $document = EstimateGenerationDocument::query()->find($this->documentId);

        if (! $document instanceof EstimateGenerationDocument || in_array($document->status, ['ready', 'ignored'], true)) {
            return;
        }

        app(DocumentProcessingStatusService::class)->markFailed(
            $document,
            'document_manifest_job_failed',
            'estimate_generation.ocr_provider_error',
            ['failure_fingerprint' => hash('sha256', $error::class)],
        );
    }
}
