<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentLifecycleState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentLifecycleStateTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function documentStages(): iterable
    {
        yield 'stored' => ['stored', 'preparing_file'];
        yield 'preflight' => ['preflight', 'preparing_file'];
        yield 'pdf text layer' => ['pdf_text_layer', 'reading_structure'];
        yield 'ocr request' => ['ocr_request', 'reading_structure'];
        yield 'ocr polling' => ['ocr_polling', 'reading_structure'];
        yield 'spreadsheet extraction' => ['spreadsheet_extraction', 'reading_structure'];
        yield 'normalization' => ['normalization', 'understanding_content'];
        yield 'fact extraction' => ['fact_extraction', 'understanding_content'];
        yield 'quality check' => ['quality_check', 'checking_relationships'];
        yield 'completed' => ['completed', 'checking_relationships'];
    }

    #[Test]
    #[DataProvider('documentStages')]
    public function processing_stage_maps_to_one_of_the_four_ui_lifecycle_states(string $processingStage, string $expectedLifecycleStage): void
    {
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'status' => 'processing',
            'processing_stage' => $processingStage,
        ]);

        self::assertSame([
            'stage' => $expectedLifecycleStage,
            'translation_key' => 'estimate_generation.document_lifecycle_'.$expectedLifecycleStage,
        ], DocumentLifecycleState::forDocument($document));
    }
}
