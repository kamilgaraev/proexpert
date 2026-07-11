<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\PdfVectorGeometryProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfVectorGeometryProviderTest extends TestCase
{
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
            self::assertNotEmpty($result->entities);
            self::assertMatchesRegularExpression('/^page:1:object:\d+$/', $result->entities[0]['handle']);
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
}
