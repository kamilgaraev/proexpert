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

    /** @return iterable<string, array{string, BenchmarkSourceType, string, list<string>}> */
    public static function invalidDescriptors(): iterable
    {
        yield 'ppm body' => ["P3\n2 2\n255\n0 0 0", BenchmarkSourceType::PhotoPlan, 'input.ppm', []];
        yield 'svg script' => ['<svg viewBox="0 0 1 1"><script/></svg>', BenchmarkSourceType::DimensionedSketch, 'input.svg', []];
        yield 'svg doctype' => ['<!DOCTYPE svg><svg viewBox="0 0 1 1"><path d="M0 0L1 1"/></svg>', BenchmarkSourceType::UndimensionedSketch, 'input.svg', []];
        yield 'dxf sections' => ["0\nSECTION\n2\nHEADER\n0\nEOF\n", BenchmarkSourceType::Dxf, 'input.dxf', []];
        yield 'dwg version' => ['AC9999 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1.', BenchmarkSourceType::Dwg, 'input.dwg', ['descriptor_validation', 'unsupported_conversion']];
        yield 'dwg unsupported policy' => ['AC1027 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1', BenchmarkSourceType::Dwg, 'input.dwg', ['descriptor_validation']];
        yield 'dwg policy' => ['AC1027 SYNTHETIC-LICENSED-DESCRIPTOR DWG conversion intentionally unsupported in Task 1.', BenchmarkSourceType::Dwg, 'input.dwg', []];
        yield 'extension mismatch' => ["P3\n1 1\n255\n0 0 0", BenchmarkSourceType::PhotoPlan, 'input.svg', []];
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
