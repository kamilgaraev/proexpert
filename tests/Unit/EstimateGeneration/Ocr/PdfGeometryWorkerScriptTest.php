<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\PdfGeometryExtractionException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryWorker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PdfGeometryWorkerScriptTest extends TestCase
{
    public function test_worker_surfaces_structured_pdf_failure_code(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'most_pdf_failure_');
        self::assertIsString($script);
        self::assertNotFalse(file_put_contents(
            $script,
            '<?php fwrite(STDERR, json_encode(["error" => "pdf_path_operator_unsupported"])); exit(2);',
        ));

        try {
            (new PdfGeometryWorker(
                scriptPath: $script,
                pythonBinary: PHP_BINARY,
                timeoutSeconds: 5,
                maxPages: 1,
                maxVectorElements: 1,
            ))->extract(
                '%PDF-1.7',
                'unsupported-path.pdf',
                static fn (): array => [],
            );
            self::fail('Structured worker failure was not surfaced.');
        } catch (PdfGeometryExtractionException $exception) {
            self::assertSame(
                '{"error":"pdf_path_operator_unsupported"}',
                $exception->context['stderr'] ?? null,
                $exception->getPrevious() === null
                    ? 'No previous process failure.'
                    : $exception->getPrevious()::class.': '.$exception->getPrevious()->getMessage(),
            );
            self::assertSame('pdf_path_operator_unsupported', $exception->safeCode);
        } finally {
            @unlink($script);
        }
    }

    public function test_pdf_failure_context_is_forwarded_to_typed_failure(): void
    {
        $exception = new PdfGeometryExtractionException(
            'pdf_geometry_process_failed',
            ['exit_code' => 2, 'stderr' => '{"error":"pdf_invalid"}'],
        );

        self::assertSame(
            ['exit_code' => 2, 'stderr' => '{"error":"pdf_invalid"}'],
            $exception->safeContext,
        );
    }

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
                        'version_id' => 'version-'.$pageNumber,
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

    public function test_vector_object_budget_degrades_to_raster_preview_instead_of_rejecting_pdf(): void
    {
        $pdf = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks/regression/replay-vector-pdf-001/input.pdf';

        $payload = (new PdfGeometryWorker(
            scriptPath: dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py',
            pythonBinary: 'python',
            timeoutSeconds: 45,
            maxPages: 200,
            maxVectorElements: 1,
        ))->extract(
            (string) file_get_contents($pdf),
            basename($pdf),
            static function (int $pageNumber, string $path, array $metadata): array {
                $bytes = (string) file_get_contents($path);

                return [
                    'artifact_path' => 's3://org-1/pdf/page-'.$pageNumber.'.png',
                    'content_type' => 'image/png',
                    'sha256' => hash('sha256', $bytes),
                    'bytes' => strlen($bytes),
                    'version_id' => 'version-'.$pageNumber,
                    'width' => $metadata['width'],
                    'height' => $metadata['height'],
                ];
            },
        );

        self::assertNotEmpty($payload['pages'] ?? []);
        self::assertContains('pdf_vector_object_limit_reached', $payload['metadata']['warnings'] ?? []);
        self::assertSame('image/png', $payload['pages'][0]['preview']['content_type'] ?? null);
    }

    public function test_vector_parser_failure_degrades_to_raster_contract_for_preview_processing(): void
    {
        $module = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py';
        $pdf = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks/regression/replay-vector-pdf-001/input.pdf';
        $script = <<<'PYTHON'
import importlib.util
import json
import sys

spec = importlib.util.spec_from_file_location("pdf_geometry_extract", sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
args = module.parser().parse_args(["--input", sys.argv[2], "--render-preview"])
module.extract = lambda _args: (_ for _ in ()).throw(RuntimeError("unsupported vector object"))
print(json.dumps(module.extract_with_raster_fallback(args)))
PYTHON;
        $process = new Process(['python', '-c', $script, $module, $pdf]);
        $process->mustRun();
        $contract = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        self::assertNotEmpty($contract['pages'] ?? []);
        self::assertSame([], $contract['entities'] ?? null);
        self::assertSame([], $contract['texts'] ?? null);
        self::assertContains('pdf_vector_geometry_unavailable', $contract['warnings'] ?? []);
    }

    public function test_many_page_budget_breach_is_atomic_and_cleans_private_workspace(): void
    {
        $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'prohelper_pdf_preview_*') ?: [];
        $published = 0;

        try {
            (new PdfGeometryWorker(
                scriptPath: dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py',
                pythonBinary: 'python',
                timeoutSeconds: 45,
                maxPages: 20,
                maxVectorElements: 5000,
                maxPreviewTotalBytes: 100_000_000,
                maxPreviewTotalPixels: 595 * 842 * 2,
            ))->extract(
                $this->multiPagePdf(8),
                'many-pages.pdf',
                function (int $pageNumber, string $path, array $metadata) use (&$published): array {
                    $published++;
                    $bytes = (string) file_get_contents($path);

                    return [
                        'artifact_path' => 's3://org-1/pdf/page-'.$pageNumber.'.png',
                        'content_type' => 'image/png',
                        'sha256' => hash('sha256', $bytes),
                        'bytes' => strlen($bytes),
                        'version_id' => 'version-'.$pageNumber,
                        'width' => $metadata['width'],
                        'height' => $metadata['height'],
                    ];
                },
            );
            self::fail('Aggregate pixel budget was not enforced.');
        } catch (PdfGeometryExtractionException $exception) {
            self::assertStringContainsString('pdf_preview_aggregate_pixels_limit', $exception->getMessage());
        }

        $after = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'prohelper_pdf_preview_*') ?: [];
        self::assertSame(0, $published);
        self::assertSame($before, $after);
    }

    public static function committedPdfProvider(): iterable
    {
        yield 'scanned' => ['regression/replay-scanned-pdf-001/input.pdf'];
        yield 'vector' => ['regression/replay-vector-pdf-001/input.pdf'];
    }

    private function multiPagePdf(int $pages): string
    {
        $path = tempnam(sys_get_temp_dir(), 'most_pdf_budget_');
        self::assertIsString($path);
        $script = 'import pypdfium2 as p,sys; d=p.PdfDocument.new(); '
            .'[d.new_page(595,842) for _ in range(int(sys.argv[2]))]; d.save(sys.argv[1], version=17)';
        $process = new Process(['python', '-c', $script, $path, (string) $pages]);
        $process->mustRun();
        $content = file_get_contents($path);
        @unlink($path);
        self::assertIsString($content);

        return $content;
    }
}
