<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\Metrics\MetricRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkMetricTest extends TestCase
{
    #[Test]
    public function perfect_prediction_has_exact_perfect_metrics(): void
    {
        $expected = $this->expected();
        $results = MetricRegistry::standard()->calculate($expected, $expected, true);

        foreach ($results as $result) {
            self::assertSame(1.0, $result->value, $result->name);
            self::assertFalse($result->overflow);
        }
        self::assertSame(0.0, $results['area_mape']->rawValue);
        self::assertSame(0.0, $results['quantity_mape']->rawValue);
        self::assertSame(0.0, $results['cost_mape']->rawValue);
    }

    #[Test]
    public function partial_empty_zero_and_outlier_semantics_are_finite_and_explicit(): void
    {
        $prediction = [
            'sheet_type' => 'photo',
            'room_cells' => ['b', 'c'],
            'wall_cells' => [],
            'opening_ids' => ['door-a', 'ghost'],
            'areas' => ['room-a' => '1', 'room-b' => '150'],
            'quantities' => [],
            'work_ids' => ['work-a'],
            'normative_rankings' => ['work-a' => ['wrong', 'norm-a']],
            'costs' => ['work-a' => '10000'],
            'evidence_ids_by_item' => [],
        ];

        $results = MetricRegistry::standard()->calculate($this->expected(), $prediction, true);

        self::assertSame(0.0, $results['sheet_classification_accuracy']->value);
        self::assertSame(1 / 3, $results['room_iou']->value);
        self::assertSame(0.0, $results['wall_iou']->value);
        self::assertSame(0.5, $results['opening_f1']->value);
        self::assertSame(0.0, $results['normative_top1']->value);
        self::assertSame(0.5, $results['normative_top3']->value);
        self::assertSame(1.0, $results['technical_success_rate']->value);
        self::assertTrue($results['area_mape']->overflow);
        self::assertTrue($results['cost_mape']->overflow);
        self::assertTrue(is_finite($results['quantity_mape']->value));

        $failed = MetricRegistry::standard()->calculate($this->expected(), [], false);
        self::assertSame(0.0, $failed['technical_success_rate']->value);
        self::assertSame(0.0, $failed['room_iou']->value);
        self::assertSame(0.0, $failed['area_mape']->value);
    }

    #[Test]
    public function empty_expected_sets_have_defined_denominators(): void
    {
        $empty = [
            'sheet_type' => 'unknown',
            'room_cells' => [],
            'wall_cells' => [],
            'opening_ids' => [],
            'areas' => [],
            'quantities' => [],
            'work_ids' => [],
            'normative_rankings' => [],
            'costs' => [],
            'applicable_item_ids' => [],
            'evidence_ids_by_item' => [],
        ];

        $results = MetricRegistry::standard()->calculate($empty, $empty, true);

        self::assertSame(1.0, $results['room_iou']->value);
        self::assertSame(1.0, $results['opening_f1']->value);
        self::assertSame(1.0, $results['work_recall']->value);
        self::assertSame(1.0, $results['evidenced_applicable_items']->value);
        self::assertSame(0.0, $results['area_mape']->rawValue);
    }

    /** @return array<string, mixed> */
    private function expected(): array
    {
        return [
            'sheet_type' => 'floor_plan',
            'room_cells' => ['a', 'b'],
            'wall_cells' => ['w1', 'w2'],
            'opening_ids' => ['door-a', 'window-a'],
            'areas' => ['room-a' => '0', 'room-b' => '100'],
            'quantities' => ['wall' => '10'],
            'work_ids' => ['work-a', 'work-b'],
            'normative_rankings' => ['work-a' => ['norm-a'], 'work-b' => ['norm-b']],
            'costs' => ['work-a' => '100'],
            'applicable_item_ids' => ['work-a', 'work-b'],
            'evidence_ids_by_item' => ['work-a' => ['e1'], 'work-b' => ['e2']],
        ];
    }
}
