<?php

declare(strict_types=1);

namespace Tests\Unit\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementDiff;
use PHPUnit\Framework\TestCase;

final class WorkVolumeStatementDiffTest extends TestCase
{
    public function test_equal_names_at_different_places_remain_distinct_and_micro_quantity_changes_are_visible(): void
    {
        $first = ['line_key' => 'one', 'name' => 'Стена', 'unit_code' => 'м2', 'quantity' => '999999999999999999.000001', 'place' => ['axis' => 'А-1']];
        $second = [...$first, 'line_key' => 'two', 'place' => ['axis' => 'А-2']];
        $third = [...$first, 'line_key' => 'three', 'place' => ['axis' => 'А-3']];
        $diff = (new WorkVolumeStatementDiff())->compare([$first, $second], [$third, [...$first, 'quantity' => '999999999999999999.000002']]);
        $byKey = array_column($diff, null, 'line_key');

        self::assertCount(3, $diff);
        self::assertSame('changed', $byKey['one']['change']);
        self::assertSame(['quantity'], $byKey['one']['changed_fields']);
        self::assertSame('999999999999999999.000001', $byKey['one']['before']['quantity']);
        self::assertSame('999999999999999999.000002', $byKey['one']['after']['quantity']);
        self::assertSame('removed', $byKey['two']['change']);
        self::assertSame('added', $byKey['three']['change']);
    }

    public function test_formatting_and_place_key_order_do_not_create_false_changes(): void
    {
        $line = ['line_key' => 'one', 'name' => 'Стена', 'unit_code' => 'м2', 'quantity' => '100.000000', 'place' => ['axis' => 'А-1', 'floor' => '2']];
        self::assertSame([], (new WorkVolumeStatementDiff())->compare([$line], [[...$line, 'quantity' => '0100', 'place' => ['floor' => '2', 'axis' => 'А-1']]]));
    }
}
