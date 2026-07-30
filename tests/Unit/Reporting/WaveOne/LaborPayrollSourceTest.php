<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Contracts\ManagementPnlComponentSource;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\EffectiveLaborRateSource;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\EffectiveLaborRateFact;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\ProjectLaborEntryFact;
use App\BusinessModules\Features\TimeTracking\Reporting\EffectiveLaborRateResolver;
use App\BusinessModules\Features\TimeTracking\Reporting\Formulas\ProjectLaborCostFormula;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostProvider;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostQueryService;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostManagementPnlComponentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollReadinessFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollSourceRateFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabasePayrollReadinessAdapter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollIssueMatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessManagementPnlComponentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessQueryService;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollVersionTransitionResolver;
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
    public function labor_and_payroll_publish_exact_management_pnl_components(): void
    {
        self::assertContains(
            ManagementPnlComponentSource::class,
            class_implements(ProjectLaborCostManagementPnlComponentSource::class),
        );
        self::assertContains(
            ManagementPnlComponentSource::class,
            class_implements(PayrollReadinessManagementPnlComponentSource::class),
        );
        $manifest = require dirname(__DIR__, 4).'/config/Reporting/management-pnl-components.php';

        self::assertSame(
            ProjectLaborCostManagementPnlComponentSource::class,
            $manifest['project_labor_cost']['source'],
        );
        self::assertSame(
            PayrollReadinessManagementPnlComponentSource::class,
            $manifest['payroll_readiness']['source'],
        );
        self::assertSame(
            'approved-time-entry-reporting-fact.v1',
            $manifest['project_labor_cost']['source_schema_version'],
        );
        self::assertSame(
            'payroll-readiness-snapshot.v1',
            $manifest['payroll_readiness']['source_schema_version'],
        );
    }

    #[Test]
    public function payroll_issue_drill_reference_is_audit_gated_and_reauthorized(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/WorkforceManagement/Reporting/'
            .'Infrastructure/DatabasePayrollReadinessAdapter.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            "'entity_type' => 'payroll_calculation_issue'",
            $source,
        );
        self::assertStringContainsString(
            "unset(\$row['audit_refs'], \$row['calculation_version_id'], \$row['issue_id'])",
            $source,
        );
        self::assertStringContainsString(
            "\$this->assertScopedResource(",
            $source,
        );
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

    #[Test]
    public function payroll_totals_count_a_source_once_while_preserving_all_of_its_issues(): void
    {
        $adapter = new DatabasePayrollReadinessAdapter(
            $this->createMock(ConnectionInterface::class),
            new PayrollReadinessFormula,
            new PayrollSourceRateFormula,
        );
        $totals = new ReflectionMethod($adapter, 'totals');
        $totals->setAccessible(true);
        $base = [
            'row_type' => 'source',
            'row_key' => 'source-1',
            'calculation_version_id' => 3,
            'source_row_id' => 11,
            'hours' => '8.0000',
            'rate' => '100.0000',
            'amount' => '800.0000',
            'currency' => 'RUB',
        ];

        $result = $totals->invoke($adapter, [
            [...$base, 'issue_id' => 41, 'issue_code' => 'hours_mismatch', 'severity' => 'blocking'],
            [...$base, 'row_key' => 'source-2', 'issue_id' => 42, 'issue_code' => 'rate_warning', 'severity' => 'warning'],
        ]);

        self::assertSame(1, $result['source_rows']);
        self::assertSame('8.00', $result['source_hours']);
        self::assertSame(['RUB' => '800.00'], $result['source_amounts']);
        self::assertSame(1, $result['blocking_issues']);
        self::assertSame(1, $result['warnings']);
        self::assertFalse($result['ready']);
    }

    #[Test]
    public function payroll_state_is_resolved_from_append_only_transitions_at_exact_as_of(): void
    {
        $resolver = new PayrollVersionTransitionResolver;
        $transitions = [
            (object) ['status' => 'built', 'transitioned_at' => '2026-07-10T08:00:00+00:00', 'id' => 1],
            (object) ['status' => 'validated', 'transitioned_at' => '2026-07-10T09:00:00+00:00', 'id' => 2],
            (object) ['status' => 'locked', 'transitioned_at' => '2026-07-10T12:00:00+00:00', 'id' => 3],
        ];

        self::assertSame(
            'validated',
            $resolver->at($transitions, new DateTimeImmutable('2026-07-10T10:00:00+00:00')),
        );
        self::assertSame(
            'locked',
            $resolver->at($transitions, new DateTimeImmutable('2026-07-10T12:00:00+00:00')),
        );
    }

    #[Test]
    public function payroll_preserves_every_issue_linked_to_the_exact_filtered_source_row(): void
    {
        $matcher = new PayrollIssueMatcher;
        $issues = [
            (object) [
                'source_row_id' => 11,
                'employee_id' => 7,
                'project_id' => 20,
                'severity' => 'blocking',
            ],
            (object) [
                'source_row_id' => 11,
                'employee_id' => 7,
                'project_id' => 20,
                'severity' => 'warning',
            ],
        ];

        self::assertSame(
            ['blocking', 'warning'],
            array_map(
                static fn (object $issue): string => (string) $issue->severity,
                $matcher->forSourceRow($issues, 11),
            ),
        );
        self::assertSame([], $matcher->forSourceRow($issues, 13));
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
