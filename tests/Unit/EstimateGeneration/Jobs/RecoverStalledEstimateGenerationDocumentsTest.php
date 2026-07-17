<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Jobs;

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
        self::assertStringNotContainsString("->whereNull('ocr_started_at')", $recovery);
        self::assertStringContainsString('ProcessEstimateGenerationDocumentJob::CONNECTION', $recovery);
        self::assertStringContainsString('ProcessEstimateGenerationDocumentJob::RECOVERY_QUEUE', $recovery);
        self::assertStringContainsString('RecoverStalledEstimateGenerationDocuments::class', $provider);
        self::assertStringContainsString("->name('estimate-generation:recover-stalled-documents')", $provider);
        self::assertStringContainsString('->onOneServer()', $provider);
        self::assertStringNotContainsString('new RecoverStalledEstimateGenerationDocumentsJob', $provider);
        self::assertStringContainsString('Recovered stalled document jobs', $recovery);
    }
}
