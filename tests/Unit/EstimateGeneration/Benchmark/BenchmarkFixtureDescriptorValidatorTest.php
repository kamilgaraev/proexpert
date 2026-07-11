<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkFixtureDescriptorValidator;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkManifestException;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkFixtureDescriptorValidatorTest extends TestCase
{
    #[Test]
    public function vector_and_scanned_pdf_fixtures_are_structurally_distinct_valid_single_page_documents(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $validator = new BenchmarkFixtureDescriptorValidator;

        $vector = $validator->pdf($root.'/development/vector-house-001/input.pdf', 'vector_pdf');
        $scan = $validator->pdf($root.'/regression/scan-house-001/input.pdf', 'scanned_pdf');

        self::assertSame(1, $vector['page_count']);
        self::assertTrue($vector['has_text']);
        self::assertFalse($vector['has_raster_image']);
        self::assertSame(1, $scan['page_count']);
        self::assertFalse($scan['has_text']);
        self::assertTrue($scan['has_raster_image']);
    }

    #[Test]
    public function every_repository_source_descriptor_is_accepted_by_its_bounded_validator(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/benchmarks';
        $validator = new BenchmarkFixtureDescriptorValidator;
        $fixtures = [
            [BenchmarkSourceType::PhotoPlan, 'development/photo-plan-001/input.ppm'],
            [BenchmarkSourceType::DimensionedSketch, 'development/dimensioned-sketch-001/input.svg'],
            [BenchmarkSourceType::UndimensionedSketch, 'regression/undimensioned-sketch-001/input.svg'],
            [BenchmarkSourceType::Dxf, 'regression/dxf-house-001/input.dxf'],
            [BenchmarkSourceType::Dwg, 'regression/dwg-placeholder-001/input.dwg'],
        ];
        foreach ($fixtures as [$source, $relative]) {
            $bytes = (string) file_get_contents($root.'/'.$relative);
            $validator->validateBytes($bytes, $source, $relative, ['descriptor_validation', 'unsupported_conversion']);
            self::addToAssertionCount(1);
        }
    }

    #[Test]
    public function binary_ppm_variants_are_validated_without_tokenizing_the_body(): void
    {
        $validator = new BenchmarkFixtureDescriptorValidator;
        $validator->validateBytes("P5\n1 1\n255\n\x7f", BenchmarkSourceType::PhotoPlan, 'input.ppm', []);
        $large = "P6\n1000 333\n255\n".str_repeat("\0", 999_000);
        $before = memory_get_peak_usage(true);
        $validator->validateBytes($large, BenchmarkSourceType::PhotoPlan, 'input.ppm', []);

        self::assertLessThanOrEqual(16 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, BenchmarkSourceType, string, list<string>}> */
    public static function invalidDescriptors(): iterable
    {
        yield 'ppm body' => ["P3\n2 2\n255\n0 0 0", BenchmarkSourceType::PhotoPlan, 'input.ppm', []];
        yield 'svg script' => ['<svg viewBox="0 0 1 1"><script/></svg>', BenchmarkSourceType::DimensionedSketch, 'input.svg', []];
        yield 'svg doctype' => ['<!DOCTYPE svg><svg viewBox="0 0 1 1"><path d="M0 0L1 1"/></svg>', BenchmarkSourceType::UndimensionedSketch, 'input.svg', []];
        yield 'svg event' => ['<svg viewBox="0 0 1 1"><rect width="1" height="1" onload="alert(1)"/></svg>', BenchmarkSourceType::DimensionedSketch, 'input.svg', []];
        yield 'svg relative href' => ['<svg viewBox="0 0 1 1"><rect width="1" height="1"/><use href="shape.svg#x"/></svg>', BenchmarkSourceType::DimensionedSketch, 'input.svg', []];
        yield 'svg xlink' => ['<svg viewBox="0 0 1 1" xmlns:xlink="http://www.w3.org/1999/xlink"><rect width="1" height="1" xlink:href="x"/></svg>', BenchmarkSourceType::DimensionedSketch, 'input.svg', []];
        yield 'svg foreign object' => ['<svg viewBox="0 0 1 1"><rect width="1" height="1"/><foreignObject/></svg>', BenchmarkSourceType::DimensionedSketch, 'input.svg', []];
        yield 'svg style url' => ['<svg viewBox="0 0 1 1"><rect width="1" height="1" style="fill:url(x)"/></svg>', BenchmarkSourceType::DimensionedSketch, 'input.svg', []];
        yield 'svg style import' => ['<svg viewBox="0 0 1 1"><style>@import "x"</style><rect width="1" height="1"/></svg>', BenchmarkSourceType::DimensionedSketch, 'input.svg', []];
        yield 'svg unknown element' => ['<svg viewBox="0 0 1 1"><rect width="1" height="1"/><animate attributeName="x"/></svg>', BenchmarkSourceType::DimensionedSketch, 'input.svg', []];
        yield 'dxf sections' => ["0\nSECTION\n2\nHEADER\n0\nEOF\n", BenchmarkSourceType::Dxf, 'input.dxf', []];
        yield 'dwg version' => ['AC9999 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1.', BenchmarkSourceType::Dwg, 'input.dwg', ['descriptor_validation', 'unsupported_conversion']];
        yield 'dwg unsupported policy' => ['AC1027 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1', BenchmarkSourceType::Dwg, 'input.dwg', ['descriptor_validation']];
        yield 'dwg policy' => ['AC1027 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1.', BenchmarkSourceType::Dwg, 'input.dwg', []];
        yield 'extension mismatch' => ["P3\n1 1\n255\n0 0 0", BenchmarkSourceType::PhotoPlan, 'input.svg', []];
        yield 'ppm huge dimensions' => ["P6\n999999 999999\n255\n", BenchmarkSourceType::PhotoPlan, 'input.ppm', []];
        yield 'ppm truncated binary' => ["P6\n2 2\n255\nabc", BenchmarkSourceType::PhotoPlan, 'input.ppm', []];
        yield 'ppm extra binary' => ["P6\n1 1\n255\n1234", BenchmarkSourceType::PhotoPlan, 'input.ppm', []];
        yield 'ppm oversized comment' => ["P6\n#".str_repeat('x', 70_000)."\n1 1\n255\n123", BenchmarkSourceType::PhotoPlan, 'input.ppm', []];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDescriptors')]
    public function malformed_or_mismatched_descriptors_are_rejected(
        string $bytes,
        BenchmarkSourceType $source,
        string $locator,
        array $capabilities,
    ): void {
        $this->expectException(BenchmarkManifestException::class);
        (new BenchmarkFixtureDescriptorValidator)->validateBytes($bytes, $source, $locator, $capabilities);
    }
}
