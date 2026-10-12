<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use PHPUnit\Framework\TestCase;

final class EstimatorQaSupplierExpenseGuardTest extends TestCase
{
    public function test_rounding_and_minimum_apply_once_per_operation_and_include_known_customer_charges(): void
    {
        self::assertSame(50, EstimatorQaSupplierExpenseGuard::exposureUnitsMinor(['operation' => '70000'], 180000));
        self::assertSame(100, EstimatorQaSupplierExpenseGuard::exposureUnitsMinor(['first' => '1000', 'second' => '1000'], 180000));
        self::assertSame(500, EstimatorQaSupplierExpenseGuard::exposureUnitsMinor(['operation' => '1'], 180000, ['operation' => 500]));
        self::assertSame(0, EstimatorQaSupplierExpenseGuard::exposureUnitsMinor(['cache' => '0'], 180000));
    }

    public function test_many_tiny_operations_cannot_bypass_the_500_unit_ceiling_by_supplier_cost_alone(): void
    {
        self::assertSame(50000, EstimatorQaSupplierExpenseGuard::exposureUnitsMinor(array_fill(0, 1000, '1'), 180000));
        self::assertSame(50050, EstimatorQaSupplierExpenseGuard::exposureUnitsMinor(array_fill(0, 1001, '1'), 180000));
        self::assertSame(50050, EstimatorQaSupplierExpenseGuard::exposureUnitsMinor(['first' => '50000000', 'second' => '40000000'], 180000));
        self::assertSame(50000, EstimatorQaSupplierExpenseGuard::exposureUnitsMinor(['first' => '50000000', 'second' => '39950000'], 180000));
    }

    public function test_unknown_or_invalid_unit_price_does_not_mean_free_supplier_expense(): void
    {
        $this->expectException(UsageInvariantViolation::class);
        EstimatorQaSupplierExpenseGuard::exposureUnitsMinor(['operation' => '1'], 0);
    }
}
