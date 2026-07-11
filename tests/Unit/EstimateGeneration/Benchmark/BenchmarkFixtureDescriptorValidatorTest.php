<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkFixtureDescriptorValidator;
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
}
