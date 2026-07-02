<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryPageData;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryRecognitionMerger;
use PHPUnit\Framework\TestCase;

final class OcrGeometryMergeTest extends TestCase
{
    public function test_pdf_geometry_is_preserved_when_text_layer_has_text(): void
    {
        $recognition = new OcrRecognitionResult(
            provider: 'pdf_text_layer',
            model: 'embedded_text',
            pages: [
                new OcrPageResult(
                    pageNumber: 5,
                    text: 'Плита сбора мусора',
                    blocks: [],
                    confidence: 1.0,
                ),
            ],
        );
        $geometry = new PdfGeometryExtractionResult(
            provider: 'pymupdf',
            model: 'geometry_v1',
            pages: [
                new PdfGeometryPageData(
                    pageNumber: 5,
                    width: 842,
                    height: 595,
                    rotation: 0,
                    textBlocks: [['text' => 'Плита сбора мусора', 'bbox' => ['x' => 10, 'y' => 20, 'width' => 200, 'height' => 24]]],
                    vectorElements: [['kind' => 'line', 'bbox' => ['x' => 100, 'y' => 100, 'width' => 200, 'height' => 0]]],
                    visualMetrics: ['line_count' => 80],
                    pageRole: 'geometry_only',
                    signals: ['vector_geometry'],
                    preview: ['path' => null],
                ),
            ],
            metadata: ['page_count' => 1],
        );

        $merged = (new PdfGeometryRecognitionMerger())->merge($recognition, $geometry);

        self::assertSame('pdf_text_layer', $merged->provider);
        self::assertSame(842, $merged->pages[0]->width);
        self::assertSame(595, $merged->pages[0]->height);
        self::assertSame('geometry_only', $merged->pages[0]->rawPayload['geometry']['page_role']);
        self::assertSame(80, $merged->pages[0]->rawPayload['geometry']['visual_metrics']['line_count']);
        self::assertSame('Плита сбора мусора', $merged->pages[0]->blocks[0]['text']);
        self::assertTrue($merged->metadata['geometry_available']);
    }

    public function test_pdf_geometry_result_can_become_recognition_when_text_is_empty(): void
    {
        $geometry = new PdfGeometryExtractionResult(
            provider: 'pymupdf',
            model: 'geometry_v1',
            pages: [
                new PdfGeometryPageData(
                    pageNumber: 5,
                    width: 842,
                    height: 595,
                    rotation: 0,
                    textBlocks: [],
                    vectorElements: [['kind' => 'line']],
                    visualMetrics: ['line_count' => 80],
                    pageRole: 'geometry_only',
                    signals: ['vector_geometry', 'text_empty'],
                    preview: ['path' => null],
                ),
            ],
            metadata: ['page_count' => 1],
        );

        $recognition = (new PdfGeometryRecognitionMerger())->fromGeometry($geometry, 'drawing.pdf');

        self::assertSame('pdf_geometry', $recognition->provider);
        self::assertSame('pymupdf_geometry_v1', $recognition->model);
        self::assertSame('', $recognition->pages[0]->text);
        self::assertSame('geometry_only', $recognition->pages[0]->rawPayload['geometry']['page_role']);
        self::assertTrue($recognition->metadata['geometry_available']);
    }
}
