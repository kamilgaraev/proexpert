<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\PdfGeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryWorker;
use Tests\TestCase;

final class PdfGeometryWorkerScriptTest extends TestCase
{
    public function test_worker_extracts_geometry_from_real_pdf_page(): void
    {
        $pdf = 'C:\\Users\\kamilgaraev\\Downloads\\11174-PZU_AS_gaz_izm_4.pdf';

        if (! file_exists($pdf)) {
            self::markTestSkipped('Problem PDF is not available on this workstation.');
        }

        try {
            $payload = (new PdfGeometryWorker())->extract((string) file_get_contents($pdf), basename($pdf));
        } catch (PdfGeometryExtractionException $exception) {
            if (str_contains($exception->getMessage(), 'pymupdf_unavailable')) {
                self::markTestSkipped($exception->getMessage());
            }

            throw $exception;
        }

        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
        $page = collect($pages)->firstWhere('page_number', 5);

        self::assertIsArray($page);
        self::assertGreaterThan(0, $page['visual_metrics']['line_count'] ?? 0);
        self::assertContains($page['page_role'] ?? '', ['plan', 'geometry_only', 'detail', 'section']);
        self::assertContains('vector_geometry', $page['signals'] ?? []);
    }
}
