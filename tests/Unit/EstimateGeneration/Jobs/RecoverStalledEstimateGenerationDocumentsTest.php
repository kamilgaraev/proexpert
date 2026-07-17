<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\RecoverEstimateGenerationUnitsJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\RecoverStalledEstimateGenerationDocumentsJob;
use PHPUnit\Framework\TestCase;

final class RecoverStalledEstimateGenerationDocumentsTest extends TestCase
{
    public function test_stalled_documents_are_redispatched_to_the_dedicated_queue(): void
    {
        $root = dirname(__DIR__, 4);
        $recovery = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/RecoverStalledEstimateGenerationDocuments.php');
        $provider = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');

        self::assertIsString($recovery);
        self::assertStringContainsString("->where('status', 'queued')", $recovery);
        self::assertStringContainsString("->whereNull('ocr_started_at')", $recovery);
        self::assertStringContainsString('ProcessEstimateGenerationDocumentJob::CONNECTION', $recovery);
        self::assertStringContainsString('ProcessEstimateGenerationDocumentJob::QUEUE', $recovery);
        self::assertStringContainsString('new RecoverStalledEstimateGenerationDocumentsJob', $provider);

        $job = new RecoverStalledEstimateGenerationDocumentsJob;
        self::assertSame(RecoverEstimateGenerationUnitsJob::CONNECTION, $job->connection);
        self::assertSame(RecoverEstimateGenerationUnitsJob::QUEUE, $job->queue);
    }
}
