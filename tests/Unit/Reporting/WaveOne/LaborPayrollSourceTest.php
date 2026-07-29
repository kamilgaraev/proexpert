<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\EffectiveLaborRateSource;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\EffectiveLaborRateFact;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\ProjectLaborEntryFact;
use App\BusinessModules\Features\TimeTracking\Reporting\EffectiveLaborRateResolver;
use App\BusinessModules\Features\TimeTracking\Reporting\Formulas\ProjectLaborCostFormula;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostProvider;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostQueryService;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollReadinessFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollSourceRateFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabasePayrollReadinessAdapter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessQueryService;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LaborPayrollSourceTest extends TestCase
{
    #[Test]
    public function project_labor_cost_provider_contract(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(ProjectLaborCostProvider::class));
        self::assertContains(ReportRowQuery::class, class_implements(ProjectLaborCostQueryService::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(ProjectLaborCostQueryService::class));
    }

    #[Test]
    public function payroll_readiness_provider_contract(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(PayrollReadinessProvider::class));
        self::assertContains(ReportRowQuery::class, class_implements(PayrollReadinessQueryService::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(PayrollReadinessQueryService::class));
    }

    #[Test]
    public function labor_cost_uses_one_effective_rate_at_work_date(): void
    {
        $resolver = new EffectiveLaborRateResolver($this->rateSource([
            $this->rate(1, '2026-07-01', '2026-07-16', '25.00', 'USD'),
            $this->rate(2, '2026-07-16', null, '30.00', 'USD'),
        ]));
        $formula = new ProjectLaborCostFormula;
        $entry = new ProjectLaborEntryFact(
            timeEntryId: 41,
            organizationId: 10,
            employeeId: 7,
            projectId: 20,
            taskId: 30,
            workTypeId: 40,
            acceptedWorkId: null,
            workDate: new DateTimeImmutable('2026-07-10'),
            status: 'approved',
            hours: '8.00',
            billable: true,
            acceptedUnits: null,
            acceptedUnit: null,
        );

        $metrics = $formula->calculate(
            entry: $entry,
            rate: $resolver->atDate(10, 7, $entry->workDate),
            plannedHours: '7.00',
        );

        self::assertNotNull($metrics);
        self::assertSame('25.00', $metrics->rate);
        self::assertSame('200.00', $metrics->cost);
        self::assertSame('USD', $metrics->currency);
        self::assertSame('1.00', $metrics->hoursVariance);
        self::assertNull($metrics->costPerAcceptedUnit);
    }

    #[Test]
    public function non_approved_entries_and_ambiguous_rates_fail_closed(): void
    {
        $formula = new ProjectLaborCostFormula;
        $draft = new ProjectLaborEntryFact(
            timeEntryId: 41,
            organizationId: 10,
            employeeId: 7,
            projectId: 20,
            taskId: null,
            workTypeId: null,
            acceptedWorkId: null,
            workDate: new DateTimeImmutable('2026-07-10'),
            status: 'draft',
            hours: '8.00',
            billable: true,
            acceptedUnits: null,
            acceptedUnit: null,
        );

        self::assertNull($formula->calculate($draft, null, null));

        $resolver = new EffectiveLaborRateResolver($this->rateSource([
            $this->rate(1, '2026-07-01', null, '25.00', 'USD'),
            $this->rate(2, '2026-07-01', null, '26.00', 'USD'),
        ]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('LABOR_RATE_OVERLAP');
        $resolver->atDate(10, 7, new DateTimeImmutable('2026-07-10'));
    }

    #[Test]
    public function missing_rate_currency_never_creates_implicit_money(): void
    {
        $resolver = new EffectiveLaborRateResolver($this->rateSource([
            $this->rate(1, '2026-07-01', null, '25.00', null),
        ]));
        $entry = new ProjectLaborEntryFact(
            timeEntryId: 41,
            organizationId: 10,
            employeeId: 7,
            projectId: 20,
            taskId: null,
            workTypeId: null,
            acceptedWorkId: null,
            workDate: new DateTimeImmutable('2026-07-10'),
            status: 'approved',
            hours: '8.00',
            billable: true,
            acceptedUnits: null,
            acceptedUnit: null,
        );

        $metrics = (new ProjectLaborCostFormula)->calculate(
            $entry,
            $resolver->atDate(10, 7, $entry->workDate),
            null,
        );

        self::assertNotNull($metrics);
        self::assertNull($metrics->cost);
        self::assertNull($metrics->currency);
        self::assertNull($metrics->hoursVariance);
        self::assertContains('MISSING_RATE_CURRENCY', $metrics->qualityWarnings);
        self::assertContains('MISSING_PLANNED_HOURS', $metrics->qualityWarnings);
    }

    #[Test]
    public function payroll_blockers_dominate_readiness_and_zero_source_coverage_is_null(): void
    {
        $formula = new PayrollReadinessFormula;
        $blocked = $formula->calculate(
            sourceRowCount: 10,
            coveredSourceRowCount: 9,
            blockingIssueCount: 1,
            warningCount: 2,
            sourceHours: '72.00',
            sourceAmounts: ['USD' => '1800.00', 'RUB' => '9000.00'],
            unassignedHours: '0.00',
            unratedHours: '8.00',
        );
        $empty = $formula->calculate(
            sourceRowCount: 0,
            coveredSourceRowCount: 0,
            blockingIssueCount: 0,
            warningCount: 0,
            sourceHours: '0.00',
            sourceAmounts: [],
            unassignedHours: '0.00',
            unratedHours: '0.00',
        );

        self::assertFalse($blocked->ready);
        self::assertSame('90.00', $blocked->coveragePercent);
        self::assertSame('30.00', $blocked->issueRate);
        self::assertSame('blocked', $blocked->readinessState);
        self::assertSame(['RUB' => '9000.00', 'USD' => '1800.00'], $blocked->sourceAmounts);
        self::assertNull($empty->coveragePercent);
        self::assertFalse($empty->ready);
        self::assertSame('unavailable', $empty->readinessState);
        self::assertSame([], $empty->sourceAmounts);
    }

    #[Test]
    public function payroll_source_currency_changes_source_identity(): void
    {
        $formula = new PayrollSourceRateFormula;
        $usd = $formula->calculate(
            hours: '8.0000',
            rate: '225.0000',
            rateType: 'hourly',
            currency: 'USD',
        );
        $rub = $formula->calculate(
            hours: '8.0000',
            rate: '225.0000',
            rateType: 'hourly',
            currency: 'RUB',
        );

        self::assertNotSame($usd->currency, $rub->currency);
        self::assertSame($usd->amount, $rub->amount);
    }

    #[Test]
    public function payroll_source_hash_covers_rate_version_and_currency(): void
    {
        $adapter = new DatabasePayrollReadinessAdapter(
            $this->createMock(ConnectionInterface::class),
            new PayrollReadinessFormula,
            new PayrollSourceRateFormula,
        );
        $rowHash = new ReflectionMethod($adapter, 'rowHash');
        $rowHash->setAccessible(true);
        $row = [
            'source_row_id' => 1,
            'employee_id' => 7,
            'project_id' => 20,
            'work_date' => '2026-07-10',
            'source_type' => 'timesheet',
            'hours' => '8.0000',
            'rate_version_id' => 3,
            'rate_type' => 'hourly',
            'rate' => '225.0000',
            'amount' => '1800.0000',
            'currency' => 'USD',
            'source_refs' => [],
        ];
        $usdHash = $rowHash->invoke($adapter, $row);
        $row['currency'] = 'RUB';
        $rubHash = $rowHash->invoke($adapter, $row);
        $row['rate_version_id'] = 4;
        $newRateVersionHash = $rowHash->invoke($adapter, $row);

        self::assertNotSame($usdHash, $rubHash);
        self::assertNotSame($rubHash, $newRateVersionHash);
    }

    #[Test]
    public function payroll_uses_decimal_effective_hourly_rate_and_explicit_currency(): void
    {
        $amount = (new PayrollSourceRateFormula)->calculate(
            hours: '7.1250',
            rate: '123.4567',
            rateType: 'hourly',
            currency: 'RUB',
        );

        self::assertSame('879.6290', $amount->amount);
        self::assertSame('123.4567', $amount->rate);
        self::assertSame('RUB', $amount->currency);
    }

    #[Test]
    public function payroll_totals_are_derived_only_from_filtered_snapshot_rows(): void
    {
        $adapter = new DatabasePayrollReadinessAdapter(
            $this->createMock(ConnectionInterface::class),
            new PayrollReadinessFormula,
            new PayrollSourceRateFormula,
        );
        $totals = new ReflectionMethod($adapter, 'totals');
        $totals->setAccessible(true);
        $result = $totals->invoke($adapter, [[
            'row_type' => 'source',
            'hours' => '8.0000',
            'rate' => '100.0000',
            'amount' => '800.0000',
            'currency' => 'RUB',
            'issue_code' => null,
            'severity' => null,
        ]]);

        self::assertSame(1, $result['source_rows']);
        self::assertSame(1, $result['covered_source_rows']);
        self::assertSame(['RUB' => '800.00'], $result['source_amounts']);
        self::assertSame(0, $result['blocking_issues']);
        self::assertTrue($result['ready']);
    }

    private function rateSource(array $rates): EffectiveLaborRateSource
    {
        return new class($rates) implements EffectiveLaborRateSource
        {
            public function __construct(private readonly array $rates) {}

            public function forEmployee(int $organizationId, int $employeeId): array
            {
                return array_values(array_filter(
                    $this->rates,
                    static fn (EffectiveLaborRateFact $fact): bool => $fact->organizationId === $organizationId
                        && $fact->employeeId === $employeeId,
                ));
            }
        };
    }

    private function rate(
        int $id,
        string $validFrom,
        ?string $validTo,
        string $amount,
        ?string $currency,
    ): EffectiveLaborRateFact {
        return new EffectiveLaborRateFact(
            rateId: $id,
            organizationId: 10,
            employeeId: 7,
            amount: $amount,
            currency: $currency,
            rateType: 'hourly',
            validFrom: new DateTimeImmutable($validFrom),
            validToExclusive: $validTo === null ? null : new DateTimeImmutable($validTo),
            sourceVersion: 3,
        );
    }
}
