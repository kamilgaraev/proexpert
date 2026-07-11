<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LegacyPdfGeometryAdapterTest extends TestCase
{
    #[Test]
    public function legacy_adapter_preserves_real_segments_bbox_style_and_metrics(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-pdf-').'.pdf';
        file_put_contents($path, $this->pdf('10 20 m 110 20 l 110 120 l h 2 w S'));
        try {
            $script = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py';
            $process = new Process(['python', $script, '--input', $path, '--max-pages', '2', '--max-vector-elements', '100']);
            $process->mustRun();
            $payload = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $element = $payload['pages'][0]['vector_elements'][0];

            self::assertSame('pypdfium2', $payload['provider']);
            self::assertSame([[10.0, 20.0], [110.0, 20.0]], $element['geometry']['points']);
            self::assertSame(['x' => 10.0, 'y' => 20.0, 'width' => 100.0, 'height' => 0.0], $element['bbox']);
            self::assertSame(2.0, $element['style']['stroke_width']);
            self::assertSame(1, $payload['pages'][0]['visual_metrics']['path_count']);
            self::assertGreaterThanOrEqual(2, $payload['pages'][0]['visual_metrics']['line_count']);
        } finally {
            @unlink($path);
        }
    }

    private function pdf(string $stream): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 300] /Resources << >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n";
        for ($i = 1; $i <= 4; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf."trailer << /Size 5 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
