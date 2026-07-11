<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\PdfVectorGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\BoundedStorageReader;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfVectorGeometryProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function real_vector_pdf_preserves_page_identity_rotation_and_objects(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vector-pdf-').'.pdf';
        file_put_contents($path, $this->pdf("0 0 m 100 0 l 100 100 l S\nBT /F1 12 Tf 10 20 Td (Plan) Tj ET"));

        try {
            $result = (new PdfVectorGeometryProvider('python', dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py'))->extractLocal($path);
            self::assertSame(1, $result->schemaVersion);
            self::assertSame('vector', $result->pages[0]['classification']);
            self::assertSame(90, $result->pages[0]['rotation']);
            self::assertSame([0.0, 0.0, 200.0, 300.0], $result->pages[0]['media_box']);
            self::assertSame([0.0, -1.0, 1.0, 0.0, 0.0, 200.0], $result->pages[0]['transform']);
            self::assertNotEmpty($result->entities);
            self::assertMatchesRegularExpression('/^page:1:object:\d+$/', $result->entities[0]['handle']);
            self::assertSame('move', $result->entities[0]['segments'][0]['operator']);
            self::assertSame([0.0, 200.0], $result->entities[0]['segments'][0]['points'][0]);
            self::assertSame('line', $result->entities[0]['segments'][1]['operator']);
            self::assertSame([0.0, 100.0], $result->entities[0]['segments'][1]['points'][1]);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function scanned_only_pdf_is_a_typed_review_requirement(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'raster-pdf-').'.pdf';
        file_put_contents($path, $this->pdf(''));
        try {
            $this->expectExceptionMessage('pdf_vector_geometry_missing');
            (new PdfVectorGeometryProvider('python', dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py'))->extractLocal($path);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function private_s3_entrypoint_enforces_organization_scope_and_bounded_read(): void
    {
        $content = $this->pdf('0 0 m 10 0 l S');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('size')->once()->with('org-42/drawings/plan.pdf')->andReturn(strlen($content));
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);
        $disk->shouldReceive('readStream')->once()->andReturn($stream);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('disk')->once()->andReturn($disk);
        $organization = new Organization;
        $organization->id = 42;
        $provider = new PdfVectorGeometryProvider(
            'python',
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py',
            fileService: $files,
            reader: new BoundedStorageReader,
        );

        self::assertNotEmpty($provider->extract('org-42/drawings/plan.pdf', $organization)->entities);
        $this->expectExceptionMessage('pdf_storage_scope_invalid');
        $provider->extract('org-7/drawings/plan.pdf', $organization);
    }

    #[Test]
    public function rotated_mixed_multipage_pdf_preserves_boxes_and_classification(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mixed-pdf-').'.pdf';
        file_put_contents($path, $this->mixedPdf());
        try {
            $result = (new PdfVectorGeometryProvider('python', dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py'))->extractLocal($path);
            self::assertCount(2, $result->pages);
            self::assertSame('vector', $result->pages[0]['classification']);
            self::assertSame('mixed', $result->pages[1]['classification']);
            self::assertSame([10.0, 20.0, 190.0, 280.0], $result->pages[1]['crop_box']);
            self::assertSame([0.0, -1.0, 1.0, 0.0, -20.0, 190.0], $result->pages[1]['transform']);
            self::assertSame(260.0, $result->pages[1]['width']);
            self::assertSame(180.0, $result->pages[1]['height']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function malformed_signature_is_rejected_before_worker_start(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad-pdf-').'.pdf';
        file_put_contents($path, 'not pdf');
        try {
            $this->expectExceptionMessage('pdf_signature_mismatch');
            (new PdfVectorGeometryProvider)->extractLocal($path);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function timeout_and_output_limits_are_typed_and_cleanup_workspace(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'safe-pdf-').'.pdf';
        file_put_contents($pdf, $this->pdf('0 0 m 1 0 l S'));
        $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-pdf-*');
        $timeoutScript = $this->temporaryScript("import time\ntime.sleep(3)\n");
        try {
            (new PdfVectorGeometryProvider('python', $timeoutScript, timeoutSeconds: 1))->extractLocal($pdf);
            self::fail('Timeout must fail.');
        } catch (\Throwable $exception) {
            self::assertSame('pdf_runtime_timeout', $exception->getMessage());
            self::assertSame($before, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-pdf-*'));
        } finally {
            @unlink($timeoutScript);
        }
        $outputScript = $this->temporaryScript("print('x' * 100000)\n");
        try {
            $this->expectExceptionMessage('pdf_runtime_output_oversize');
            (new PdfVectorGeometryProvider('python', $outputScript, maxOutputBytes: 1024))->extractLocal($pdf);
        } finally {
            @unlink($outputScript);
            @unlink($pdf);
        }
    }

    #[Test]
    public function workspace_creation_failure_is_typed(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'safe-pdf-').'.pdf';
        $invalidRoot = tempnam(sys_get_temp_dir(), 'pdf-root-file-');
        file_put_contents($pdf, $this->pdf('0 0 m 1 0 l S'));
        try {
            $this->expectExceptionMessage('pdf_workspace_failed');
            (new PdfVectorGeometryProvider('python', workspaceRoot: $invalidRoot))->extractLocal($pdf);
        } finally {
            @unlink($pdf);
            @unlink($invalidRoot);
        }
    }

    private function pdf(string $stream): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 300] /Rotate 90 /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf."trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function mixedPdf(): string
    {
        $stream1 = '0 0 m 50 0 l S';
        $stream2 = "q 10 0 0 10 20 20 cm /Im1 Do Q\n0 0 m 0 50 l S";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 300] /Resources << >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream1)." >>\nstream\n{$stream1}\nendstream",
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 300] /CropBox [10 20 190 280] /Rotate 90 /Resources << /XObject << /Im1 7 0 R >> >> /Contents 6 0 R >>',
            '<< /Length '.strlen($stream2)." >>\nstream\n{$stream2}\nendstream",
            "<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length 1 >>\nstream\n\x00\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 8\n0000000000 65535 f \n";
        for ($i = 1; $i <= 7; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf."trailer << /Size 8 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function temporaryScript(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-runtime-').'.py';
        file_put_contents($path, $body);

        return $path;
    }
}
