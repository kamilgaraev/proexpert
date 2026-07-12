<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Benchmark;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionReplaySourceCaptureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
    }

    #[Test]
    public function vector_pdf_capture_is_the_exact_output_of_the_pinned_production_worker(): void
    {
        $source = $this->root.'/regression/replay-vector-pdf-001/input.pdf';
        $capture = $this->recording('vector-pdf-001-geometry.json');
        $actual = $this->capture(['python', dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py',
            '--input', $source, '--workspace', dirname($source), '--contract-vector']);

        self::assertSame(hash_file('sha256', $source), $capture['source_sha256']);
        self::assertSame($actual, $capture['payload']);
        self::assertSame('pdf-geometry:v1;pypdfium2:5.8.0', $actual['runtime_version']);
        self::assertGreaterThanOrEqual(7, count($actual['entities']));
        self::assertContains('4400 mm', array_column($actual['texts'], 'text'));
        self::assertContains('2900 mm', array_column($actual['texts'], 'text'));
        self::assertSame('vector', $actual['pages'][0]['classification']);
    }

    #[Test]
    public function dwg_parser_proof_matches_a_fresh_libredwg_decode(): void
    {
        $binary = getenv('LIBREDWG_DWGREAD_BINARY') ?: getenv('USERPROFILE').'/.cache/most-libredwg/0.13.4/win64/dwgread.exe';
        self::assertFileExists($binary);
        $source = $this->root.'/regression/replay-dwg-layout-001/input.dwg';
        $workspace = sys_get_temp_dir().'/most-dwg-proof-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));
        $runtimeSource = $workspace.'/input.dwg';
        self::assertTrue(copy($source, $runtimeSource));
        try {
            $actual = $this->capture(['python', dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py',
                '--input', $runtimeSource, '--workspace', $workspace, '--dwgread', $binary]);
        } finally {
            foreach (glob($workspace.'/*') ?: [] as $artifact) {
                unlink($artifact);
            }
            rmdir($workspace);
        }
        $capture = $this->recording('dwg-layout-001-geometry.json');
        $proof = $this->recording('dwg-layout-001-parser-proof.json');
        $canonical = json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        self::assertSame($actual, $capture['payload']);
        self::assertSame(hash_file('sha256', $source), $proof['source_sha256']);
        self::assertSame('cad-geometry:v1;libredwg:0.13.4', $proof['runtime_version']);
        self::assertSame(hash('sha256', $canonical), $proof['canonical_output_sha256']);
        self::assertSame(count($actual['entities']), $proof['entity_count']);
        self::assertGreaterThan(0, $proof['entity_count']);
    }

    #[Test]
    public function raster_sources_contain_visible_walls_opening_and_dimension_glyph_pixels(): void
    {
        $ppm = (string) file_get_contents($this->root.'/regression/replay-dimensioned-raster-001/input.ppm');
        self::assertStringStartsWith("P6\n400 300\n255\n", $ppm);
        $pixels = substr($ppm, strlen("P6\n400 300\n255\n"));
        self::assertSame(360_000, strlen($pixels));
        self::assertGreaterThan(5_000, substr_count($pixels, "\0\0\0"));
        self::assertSame("\xff\xff\xff", substr($pixels, (47 * 400 + 190) * 3, 3));
        self::assertSame("\0\0\0", substr($pixels, (275 * 400 + 150) * 3, 3));

        $pdf = (string) file_get_contents($this->root.'/regression/replay-scanned-pdf-001/input.pdf');
        self::assertStringContainsString('/Width 400 /Height 300', $pdf);
        self::assertGreaterThan(360_000, strlen($pdf));
        self::assertGreaterThan(5_000, substr_count($pdf, "\0\0\0"));
    }

    #[Test]
    public function svg_recordings_reference_only_visible_stable_source_features(): void
    {
        $engineering = (string) file_get_contents($this->root.'/regression/replay-engineering-layout-001/input.svg');
        foreach (['room-outline', 'door-opening', 'riser-110', 'riser-node', 'dimension-width', 'dimension-height', '6500 mm', '3700 mm'] as $feature) {
            self::assertStringContainsString($feature, $engineering);
        }
        $engineeringRecording = $this->recording('engineering-layout-001-geometry.json');
        self::assertContains('riser-110', array_column($engineeringRecording['payload']['evidence'], 'key'));
        self::assertContains('engineering-riser-110', array_column($engineeringRecording['payload']['elements'], 'key'));
        $freehand = (string) file_get_contents($this->root.'/regression/replay-freehand-review-001/input.svg');
        foreach (['uncertain-outline', 'uncertain-divider', 'review-question', '?'] as $feature) {
            self::assertStringContainsString($feature, $freehand);
        }
        $recording = $this->recording('freehand-review-001-geometry.json');
        self::assertSame('freehand-evidence', $recording['payload']['evidence'][0]['key']);
        self::assertSame('freehand-evidence', $recording['payload']['elements'][0]['evidence_ref']);
        self::assertContains('scale_missing', $recording['payload']['warnings']);
    }

    #[Test]
    public function all_six_geometry_captures_are_source_bound_and_materially_distinct(): void
    {
        $names = ['vector-pdf-001', 'scanned-pdf-001', 'dwg-layout-001', 'dimensioned-raster-001', 'freehand-review-001', 'engineering-layout-001'];
        $hashes = [];
        foreach ($names as $name) {
            $capture = $this->recording($name.'-geometry.json');
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $capture['source_sha256']);
            self::assertStringContainsString($capture['source_sha256'], json_encode($capture['payload'], JSON_THROW_ON_ERROR));
            $hashes[] = $capture['payload_sha256'];
        }
        self::assertCount(6, array_unique($hashes));
    }

    private function recording(string $name): array
    {
        return json_decode((string) file_get_contents($this->root.'/recordings/'.$name), true, 128, JSON_THROW_ON_ERROR);
    }

    private function capture(array $arguments): array
    {
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $output = shell_exec($command);
        self::assertIsString($output);
        self::assertNotSame('', $output);

        return json_decode($output, true, 128, JSON_THROW_ON_ERROR);
    }
}
