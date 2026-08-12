<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentResourceUsageSummarizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentResourceUsageSummarizerTest extends TestCase
{
    #[Test]
    public function it_aggregates_only_measured_page_units_without_summing_memory_peaks(): void
    {
        $summary = (new DocumentResourceUsageSummarizer)->summarize([
            ['document_representation' => ['resource_usage' => ['duration_ms' => 120, 'peak_memory_bytes' => 400]]],
            ['document_representation' => ['resource_usage' => ['peak_memory_bytes' => 900, 'duration_ms' => 80]]],
            ['document_representation' => ['resource_usage' => ['duration_ms' => -1, 'peak_memory_bytes' => 'unknown']]],
            [],
        ]);

        self::assertSame([
            'measured_units' => 2,
            'duration_ms_total' => 200,
            'duration_ms_max' => 120,
            'peak_memory_bytes_max' => 900,
        ], $summary);
    }

    #[Test]
    public function it_includes_failed_unit_measurements_without_double_counting_successful_outputs(): void
    {
        $summary = (new DocumentResourceUsageSummarizer)->summarize(
            [
                ['document_representation' => ['resource_usage' => ['duration_ms' => 120, 'peak_memory_bytes' => 400]]],
            ],
            [
                [
                    'status' => 'failed',
                    'metadata' => ['resource_usage' => ['duration_ms' => 75, 'peak_memory_bytes' => 800]],
                ],
                [
                    'status' => 'completed',
                    'metadata' => ['resource_usage' => ['duration_ms' => 900, 'peak_memory_bytes' => 6400]],
                ],
            ],
        );

        self::assertSame([
            'measured_units' => 2,
            'duration_ms_total' => 195,
            'duration_ms_max' => 120,
            'peak_memory_bytes_max' => 800,
        ], $summary);
    }
}
