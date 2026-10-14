<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\AcceptanceBenchmarkGate;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkContractException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcceptanceBenchmarkGateTest extends TestCase
{
    #[DataProvider('invalidMetrics')]
    public function test_nonfinite_or_out_of_range_metrics_cannot_pass_acceptance(float $value): void
    {
        $summary = array_fill_keys(['work_recall', 'normative_top3', 'evidenced_applicable_items', 'technical_success_rate', 'unknown_numeric_values_preserved'], ['macro' => 1.0]);
        $summary['work_recall']['macro'] = $value;
        $this->expectException(BenchmarkContractException::class);
        (new AcceptanceBenchmarkGate)->assertSummary(0, 0, $summary);
    }

    public static function invalidMetrics(): array
    {
        return [[NAN], [INF], [-INF], [1.01]];
    }
}
