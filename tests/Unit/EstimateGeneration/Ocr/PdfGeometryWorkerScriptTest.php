<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\PdfGeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryWorker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfGeometryWorkerScriptTest extends TestCase
{
    #[DataProvider('committedPdfProvider')]
    public function test_worker_renders_committed_pdf_inside_private_workspace(string $relativePath): void
    {
        $pdf = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks/'.$relativePath;
        $published = [];

        try {
            $payload = (new PdfGeometryWorker(
                scriptPath: dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py',
                pythonBinary: 'python',
                timeoutSeconds: 45,
                maxPages: 200,
                maxVectorElements: 5000,
            ))->extract(
                (string) file_get_contents($pdf),
                basename($pdf),
                function (int $pageNumber, string $path, array $metadata) use (&$published): array {
                    $bytes = (string) file_get_contents($path);
                    $published[$pageNumber] = hash('sha256', $bytes);

                    return [
                        'artifact_path' => 's3://org-1/pdf/page-'.$pageNumber.'.png',
                        'content_type' => 'image/png',
                        'sha256' => $published[$pageNumber],
                        'bytes' => strlen($bytes),
                        'width' => $metadata['width'],
                        'height' => $metadata['height'],
                    ];
                },
            );
        } catch (PdfGeometryExtractionException $exception) {
            if (str_contains($exception->getMessage(), 'pymupdf_unavailable')) {
                self::markTestSkipped($exception->getMessage());
            }

            throw $exception;
        }

        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
        $page = $pages[0] ?? null;

        self::assertIsArray($page);
        self::assertSame(1, $page['page_number']);
        self::assertSame('image/png', $page['preview']['content_type'] ?? null);
        self::assertSame('s3://org-1/pdf/page-1.png', $page['preview']['artifact_path'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($page['preview']['sha256'] ?? ''));
        self::assertSame($published[1], $page['preview']['sha256']);
        self::assertGreaterThan(0, $page['preview']['width'] ?? 0);
        self::assertGreaterThan(0, $page['preview']['height'] ?? 0);
        self::assertArrayNotHasKey('content_base64', $page['preview']);
    }

    public function test_worker_rejects_aggregate_preview_budget_before_publication(): void
    {
        $pdf = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks/regression/replay-scanned-pdf-001/input.pdf';
        $published = false;

        $this->expectExceptionMessage('pdf_preview_aggregate_bytes_limit');
        (new PdfGeometryWorker(
            scriptPath: dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py',
            pythonBinary: 'python',
            timeoutSeconds: 45,
            maxPages: 200,
            maxVectorElements: 5000,
            maxPreviewTotalBytes: 1,
            maxPreviewTotalPixels: 1_000_000_000,
        ))->extract(
            (string) file_get_contents($pdf),
            basename($pdf),
            function () use (&$published): array {
                $published = true;

                return [];
            },
        );

        self::assertFalse($published);
    }

    public static function committedPdfProvider(): iterable
    {
        yield 'scanned' => ['regression/replay-scanned-pdf-001/input.pdf'];
        yield 'vector' => ['regression/replay-vector-pdf-001/input.pdf'];
    }
}
