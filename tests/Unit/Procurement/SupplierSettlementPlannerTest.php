<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Support\SupplierSettlementPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SupplierSettlementPlannerTest extends TestCase
{
    public function test_postpayment_keeps_each_receipt_date(): void
    {
        $rows = (new SupplierSettlementPlanner)->build(50000, 0, 10, '2026-09-01', [
            ['id' => 2, 'date' => '2026-09-08', 'amount_minor' => 30000],
            ['id' => 1, 'date' => '2026-09-05', 'amount_minor' => 20000],
        ]);

        $this->assertSame([
            ['source_key' => 'receipt:1', 'due_date' => '2026-09-15', 'amount_minor' => 20000],
            ['source_key' => 'receipt:2', 'due_date' => '2026-09-18', 'amount_minor' => 30000],
        ], $rows);
    }

    public function test_advance_is_allocated_proportionally_without_losing_the_balance(): void
    {
        $rows = (new SupplierSettlementPlanner)->build(50000, 30, 10, '2026-09-01', [
            ['id' => 1, 'date' => '2026-09-05', 'amount_minor' => 20000],
            ['id' => 2, 'date' => '2026-09-08', 'amount_minor' => 30000],
        ]);

        $this->assertSame([15000, 14000, 21000], array_column($rows, 'amount_minor'));
        $this->assertSame(['advance', 'receipt:1', 'receipt:2'], array_column($rows, 'source_key'));
        $this->assertSame(['2026-09-01', '2026-09-15', '2026-09-18'], array_column($rows, 'due_date'));
        $this->assertSame(50000, array_sum(array_column($rows, 'amount_minor')));
    }

    public function test_unreceived_balance_has_no_invented_due_date(): void
    {
        $planner = new SupplierSettlementPlanner;
        $this->assertSame([
            ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 50000],
        ], $planner->build(50000, 0, 10, '2026-09-01', []));

        $rows = $planner->build(50000, 30, 10, '2026-09-01', [
            ['id' => 1, 'date' => '2026-09-05', 'amount_minor' => 20000],
        ]);
        $this->assertSame(['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 21000], $rows[2]);
        $this->assertSame(50000, array_sum(array_column($rows, 'amount_minor')));
    }

    public function test_rounding_preserves_pennies_across_small_batches(): void
    {
        $rows = (new SupplierSettlementPlanner)->build(3, 50, 0, '2026-09-01', [
            ['id' => 1, 'date' => '2026-09-05', 'amount_minor' => 1],
            ['id' => 2, 'date' => '2026-09-06', 'amount_minor' => 1],
            ['id' => 3, 'date' => '2026-09-07', 'amount_minor' => 1],
        ]);

        $this->assertSame([
            ['source_key' => 'advance', 'due_date' => '2026-09-01', 'amount_minor' => 2],
            ['source_key' => 'receipt:2', 'due_date' => '2026-09-06', 'amount_minor' => 1],
        ], $rows);
    }

    public function test_full_advance_and_zero_amount_do_not_create_deferred_payments(): void
    {
        $planner = new SupplierSettlementPlanner;
        $this->assertSame([
            ['source_key' => 'advance', 'due_date' => '2026-09-01', 'amount_minor' => 50000],
        ], $planner->build(50000, 100, 0, '2026-09-01', []));
        $this->assertSame([], $planner->build(0, 0, 0, '2026-09-01', []));
    }

    public function test_maximum_amount_uses_integer_arithmetic(): void
    {
        $amount = 999999999999999;
        $rows = (new SupplierSettlementPlanner)->build($amount, 99, 3650, '2026-09-01', [
            ['id' => 1, 'date' => '2026-09-05', 'amount_minor' => $amount],
        ]);

        $this->assertSame($amount, array_sum(array_column($rows, 'amount_minor')));
        $this->assertSame(989999999999999, $rows[0]['amount_minor']);
        $this->assertSame(10000000000000, $rows[1]['amount_minor']);
    }

    public function test_handles_ten_thousand_receipts_without_losing_pennies(): void
    {
        $receipts = [];
        for ($id = 10000; $id >= 1; $id--) {
            $receipts[] = ['id' => $id, 'date' => '2026-09-05', 'amount_minor' => 1];
        }
        $rows = (new SupplierSettlementPlanner)->build(10000, 37, 10, '2026-09-01', $receipts);

        $this->assertSame(3700, $rows[0]['amount_minor']);
        $this->assertSame(10000, array_sum(array_column($rows, 'amount_minor')));
        $this->assertCount(6301, $rows);
        $this->assertCount(count($rows), array_unique(array_column($rows, 'source_key')));
        $this->assertNotContains('unreceived', array_column($rows, 'source_key'));
    }

    public function test_invalid_receipt_facts_are_rejected(): void
    {
        $valid = ['id' => 1, 'date' => '2026-09-05', 'amount_minor' => 20000];
        foreach ([
            [$valid, $valid],
            [array_replace($valid, ['amount_minor' => 50001])],
            [array_replace($valid, ['amount_minor' => -1])],
            [array_replace($valid, ['amount_minor' => 200.5])],
            [array_replace($valid, ['date' => '2026-02-30'])],
            [array_replace($valid, ['date' => 'tomorrow'])],
            [array_replace($valid, ['id' => 0])],
        ] as $receipts) {
            try {
                (new SupplierSettlementPlanner)->build(50000, 30, 10, '2026-09-01', $receipts);
                $this->fail('Invalid receipt facts were accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_terms_are_rejected(): void
    {
        foreach ([[-1, 0, 0], [1000000000000000, 0, 0], [50000, -1, 10], [50000, 101, 10], [50000, 30, -1], [50000, 30, 3651], [50000, 100, 10]] as $terms) {
            try {
                (new SupplierSettlementPlanner)->build(...[...$terms, '2026-09-01', []]);
                $this->fail('Invalid payment terms were accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
