<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\RuleBasedDrawingAnalysisProvider;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\PdfGeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractor;
use Tests\TestCase;

final class ProblemPdfGeometryRegressionTest extends TestCase
{
    public function test_problem_pdf_page_five_is_not_empty_and_requires_review_without_fake_quantities(): void
    {
        $pdf = 'C:\\Users\\kamilgaraev\\Downloads\\11174-PZU_AS_gaz_izm_4.pdf';

        if (! file_exists($pdf)) {
            self::markTestSkipped('Problem PDF is not available on this workstation.');
        }

        try {
            $geometry = app(PdfGeometryExtractor::class)->extract((string) file_get_contents($pdf), basename($pdf));
        } catch (PdfGeometryExtractionException $exception) {
            if (str_contains($exception->getMessage(), 'pymupdf_unavailable')) {
                self::markTestSkipped($exception->getMessage());
            }

            throw $exception;
        }

        $page = $geometry->pageByNumber(5);

        self::assertNotNull($page);
        self::assertGreaterThan(0, $page->visualMetrics['line_count'] ?? 0);
        self::assertNotSame('empty', $page->pageRole);

        $recognition = new OcrRecognitionResult(
            provider: 'pdf_geometry',
            model: 'pymupdf_geometry_v1',
            pages: [
                new OcrPageResult(
                    pageNumber: 5,
                    text: '',
                    width: $page->width,
                    height: $page->height,
                    rawPayload: ['geometry' => $page->toArray()],
                ),
            ]
        );
        $analysis = (new RuleBasedDrawingAnalysisProvider())->analyze(10, basename($pdf), $recognition);

        self::assertNotEmpty($analysis->summary['geometry_metrics']);
        self::assertTrue($analysis->summary['document_profile']['requires_manual_review']);
        self::assertEmpty(array_filter(
            $analysis->takeoffs,
            static fn (array $takeoff): bool => ($takeoff['normalized_payload']['calculation_basis'] ?? null) === 'footprint_dimension_pair'
        ));
        self::assertContains('text_layer_empty_with_geometry', $analysis->summary['review_reasons']);
    }
}
