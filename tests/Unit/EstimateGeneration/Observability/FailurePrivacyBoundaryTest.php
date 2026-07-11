<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FailurePrivacyBoundaryTest extends TestCase
{
    #[Test]
    public function runtime_failure_hooks_never_persist_log_or_notify_with_throwable_messages(): void
    {
        foreach ([
            'Application/Sessions/AdvanceEstimateGeneration.php',
            'Jobs/GenerateEstimateDraftJob.php',
            'Jobs/ProcessEstimateGenerationDocumentJob.php',
            'Jobs/ProcessEstimateGenerationUnitJob.php',
            'Pipeline/PipelineFailureDetails.php',
            'Services/EstimateGenerationNotificationService.php',
            'Services/Ocr/OcrDocumentProcessor.php',
            'Services/Ocr/PdfTextLayerExtractor.php',
        ] as $relativePath) {
            $source = file_get_contents(dirname(__DIR__, 4)
                .'/app/BusinessModules/Addons/EstimateGeneration/'.$relativePath);
            self::assertIsString($source);
            self::assertStringNotContainsString('->getMessage()', $source, $relativePath);
            self::assertStringNotContainsString("'error' =>", $source, $relativePath);
        }
    }
}
