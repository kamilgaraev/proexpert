<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrQualityAnalyzer;
use Tests\TestCase;

class OcrQualityAnalyzerTest extends TestCase
{
    public function test_it_marks_clear_document_as_good(): void
    {
        $result = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: 'Общая площадь здания 1280 м2, 2 этажа, высота 4.2 м',
                    confidence: 0.92,
                ),
            ],
        );

        $quality = app(OcrQualityAnalyzer::class)->analyze($result);

        $this->assertSame('good', $quality->level);
        $this->assertSame(0.92, $quality->score);
        $this->assertSame([], $quality->flags);
        $this->assertSame(1, $quality->metrics['page_count']);
    }

    public function test_it_marks_empty_document_as_unusable(): void
    {
        $result = new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(pageNumber: 1, text: '', confidence: null),
            ],
        );

        $quality = app(OcrQualityAnalyzer::class)->analyze($result);

        $this->assertSame('unusable', $quality->level);
        $this->assertSame(0.0, $quality->score);
        $this->assertContains('no_text_detected', $quality->flags);
        $this->assertContains('low_quality', $quality->flags);
    }
}
