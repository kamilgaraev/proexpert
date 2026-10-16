<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EstimatorQaSupplierExpenseGuardTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['estimate-generation' => ['live_qa' => ['enabled' => true,
            'run_id' => '11111111-1111-5111-8111-111111111111']]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    #[DataProvider('quoteExposures')]
    public function test_quote_budget_uses_the_same_global_rounded_expense_and_charge_exposure(array $groups, int $units, int $cost): void
    {
        $budget = $this->guard($groups)->quoteBudget();
        self::assertSame($units, $budget['remaining_units_minor']);
        self::assertSame($cost, $budget['maximum_provider_cost_micro_rub']);
        self::assertSame('11111111-1111-5111-8111-111111111111', $budget['run_id']);
    }

    public static function quoteExposures(): array
    {
        return ['empty' => [[], 50000, 90000000],
            'failed measured live run' => [[['operation_key' => 'failed', 'supplier_exposure' => '1263574', 'charged_units_minor' => 0]], 49250, 88650000],
            'unresolved admitted maximum' => [[['operation_key' => 'unknown', 'supplier_exposure' => '88200000', 'charged_units_minor' => 0]], 1000, 1800000],
            'higher known charge rounded down for next quote' => [[['operation_key' => 'charged', 'supplier_exposure' => '1', 'charged_units_minor' => 49317]], 650, 1170000]];
    }

    public function test_an_exhausted_run_cannot_issue_another_quote(): void
    {
        $this->expectException(SessionAiCostLimitReached::class);
        $this->expectExceptionMessage('estimator_live_qa_expense_limit_reached');
        $this->guard([['operation_key' => 'spent', 'supplier_exposure' => '90000000', 'charged_units_minor' => 0]])->quoteBudget();
    }

    public function test_disabled_qa_does_not_change_regular_quotes_or_read_the_expense_ledger(): void
    {
        config()->set('estimate-generation.live_qa.enabled', false);
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('table');
        $database->expects(self::never())->method('select');
        self::assertNull((new EstimatorQaSupplierExpenseGuard($database))->quoteBudget());
    }

    private function guard(array $groups): EstimatorQaSupplierExpenseGuard
    {
        $database = $this->createMock(Connection::class);
        $database->method('transactionLevel')->willReturn(1);
        $query = $this->createMock(Builder::class);
        $database->method('table')->with('estimate_generation_qa_expense_runs')->willReturn($query);
        $query->method('where')->with('run_id', '11111111-1111-5111-8111-111111111111')->willReturnSelf();
        $query->method('first')->willReturn(null);
        $database->method('select')->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'GROUP BY operation_key')),
            ['11111111-1111-5111-8111-111111111111'])->willReturn(array_map(static fn (array $row): object => (object) $row, $groups));

        return new EstimatorQaSupplierExpenseGuard($database);
    }

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
