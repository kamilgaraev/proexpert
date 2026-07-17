<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessEstimateGenerationDocumentJobTest extends TestCase
{
    #[Test]
    public function document_processing_is_dispatched_to_the_dedicated_geometry_queue(): void
    {
        $job = new ProcessEstimateGenerationDocumentJob(
            20,
            new FailureExecutionSnapshot(
                organizationId: 3,
                projectId: 7,
                sessionId: 11,
                documentId: 20,
                stateVersion: 1,
                status: 'processing_documents',
                sourceVersion: 'sha256:source',
                attemptId: '018f4a20-3f4c-7a11-8a22-123456789abc',
                correlationId: '018f4a20-3f4c-7a11-8a22-123456789abd',
                eventId: '018f4a20-3f4c-7a11-8a22-123456789abe',
            ),
        );

        self::assertSame('estimate-generation-documents', ProcessEstimateGenerationDocumentJob::QUEUE);
        self::assertSame('estimate-generation-documents-recovery', ProcessEstimateGenerationDocumentJob::RECOVERY_QUEUE);
        self::assertSame(ProcessEstimateGenerationDocumentJob::QUEUE, $job->queue);
        self::assertSame(1800, $job->timeout);
    }

    #[Test]
    public function stale_job_is_discarded_without_reporting_an_ocr_failure(): void
    {
        $previous = Container::getInstance();
        Container::setInstance(new Container);

        try {
            $job = new ProcessEstimateGenerationDocumentJob(
                20,
                new FailureExecutionSnapshot(
                    organizationId: 3,
                    projectId: 7,
                    sessionId: 11,
                    documentId: 20,
                    stateVersion: 1,
                    status: 'processing_documents',
                    sourceVersion: 'sha256:source',
                    attemptId: '018f4a20-3f4c-7a11-8a22-123456789abc',
                    correlationId: '018f4a20-3f4c-7a11-8a22-123456789abd',
                    eventId: '018f4a20-3f4c-7a11-8a22-123456789abe',
                ),
            );

            $job->failed(new StaleEstimateGenerationState(11, 1));
            self::addToAssertionCount(1);
        } finally {
            Container::setInstance($previous);
        }
    }
}
