<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Contracts\ManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Support\ManagementPnlSourceTupleGuard;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\EffectiveLaborRateSource;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\EffectiveLaborRateFact;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\ProjectLaborEntryFact;
use App\BusinessModules\Features\TimeTracking\Reporting\EffectiveLaborRateResolver;
use App\BusinessModules\Features\TimeTracking\Reporting\Formulas\ProjectLaborCostFormula;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostManagementPnlComponentSource;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostProvider;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostQueryService;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollReadinessFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollSourceRateFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabasePayrollReadinessAdapter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollIssueMatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessManagementPnlComponentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessQueryService;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollVersionTransitionResolver;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Collection;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\Reporting\FakeReportExecutionClock;
use Tests\Support\Reporting\ReportDefinitionBuilder;

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
            '$this->assertScopedResource(',
            $source,
        );
        self::assertStringContainsString('PAYROLL_VALIDATION_ISSUE_SOURCE_SCOPE_MISMATCH', $source);
        self::assertStringContainsString('PAYROLL_AUDIT_REFERENCE_SCOPE_MISMATCH', $source);
        self::assertStringContainsString("'project_id' => \$issue->project_id", $source);
        self::assertStringContainsString("'employee_id' => \$issue->employee_id", $source);
    }

    #[Test]
    public function management_pnl_components_require_an_exact_sealed_source_tuple(): void
    {
        $root = dirname(__DIR__, 4);
        $labor = file_get_contents(
            $root.'/app/BusinessModules/Features/TimeTracking/Reporting/'
            .'ProjectLaborCostManagementPnlComponentSource.php',
        );
        $payroll = file_get_contents(
            $root.'/app/BusinessModules/Features/WorkforceManagement/Reporting/'
            .'PayrollReadinessManagementPnlComponentSource.php',
        );
        $payrollAdapter = file_get_contents(
            $root.'/app/BusinessModules/Features/WorkforceManagement/Reporting/'
            .'Infrastructure/DatabasePayrollReadinessAdapter.php',
        );
        $tupleGuard = file_get_contents(
            $root.'/app/BusinessModules/Features/Budgeting/Reporting/ManagementPnl/Support/'
            .'ManagementPnlSourceTupleGuard.php',
        );

        foreach ([$labor, $payroll] as $source) {
            self::assertIsString($source);
            self::assertStringContainsString('ReportDefinitionRegistry', $source);
            self::assertStringContainsString('->published($this->sourceReportCode())', $source);
            self::assertStringContainsString("->where('definition_hash', \$expected->definitionHash->value)", $source);
            self::assertStringContainsString("->where('scope_hash', \$scopeHash)", $source);
            self::assertStringContainsString("->where('period_from', \$periodFrom)", $source);
            self::assertStringContainsString("->where('period_to', \$periodTo)", $source);
            self::assertStringContainsString("->where('as_of', \$query->asOf", $source);
            self::assertStringContainsString("->where('management_pnl_eligible', true)", $source);
            self::assertStringContainsString("->where('quality_status', 'complete')", $source);
            self::assertStringContainsString("->where('reconciliation_status', 'matched')", $source);
            self::assertStringContainsString('selectActiveReadyRun(', $source);
            self::assertStringContainsString("->where('id', \$run->snapshot_id)", $source);
            self::assertStringContainsString("->where('query_hash', \$run->query_hash)", $source);
            self::assertStringContainsString("->where('source_hash', \$run->source_hash)", $source);
            self::assertStringContainsString('assertRunSnapshotTuple(', $source);
            self::assertStringContainsString('assertRequestedGroupCoverage', $source);
        }
        self::assertIsString($tupleGuard);
        self::assertStringContainsString('management_pnl_requested_group_coverage_gap', $tupleGuard);
        self::assertStringContainsString("->where('status', 'ready')", $tupleGuard);
        self::assertStringContainsString("->where('expires_at', '>', \$this->clock->now()", $tupleGuard);
        self::assertStringContainsString("->orderByDesc('ready_at')", $tupleGuard);
        self::assertIsString($payrollAdapter);
        self::assertStringContainsString("'employee_name' => \$row['employee_name']", $payrollAdapter);
        self::assertStringContainsString("'project_name' => \$row['project_name']", $payrollAdapter);
        self::assertStringNotContainsString(
            "join('workforce_employees as employee'",
            substr(
                $payrollAdapter,
                (int) strpos($payrollAdapter, 'public function materialize('),
                (int) strpos($payrollAdapter, 'private function persist(')
                    - (int) strpos($payrollAdapter, 'public function materialize('),
            ),
        );
    }

    #[Test]
    public function payroll_audit_reference_must_match_the_pinned_row_scope(): void
    {
        $adapter = new DatabasePayrollReadinessAdapter(
            $this->createMock(ConnectionInterface::class),
            new PayrollReadinessFormula,
            new PayrollSourceRateFormula,
        );
        $assertScope = new ReflectionMethod($adapter, 'assertAuditReferenceScope');
        $assertScope->setAccessible(true);
        $scope = new ReportScope(
            10,
            [10],
            [20],
            [
                new ReportScopedResource('project', 20, 20),
                new ReportScopedResource('employee', 7, 20),
            ],
            new DateTimeZone('UTC'),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PAYROLL_AUDIT_REFERENCE_SCOPE_MISMATCH');
        $assertScope->invoke(
            $adapter,
            $scope,
            ['project_id' => 20, 'employee_id' => 7],
            [
                'entity_type' => 'payroll_source_row',
                'entity_id' => 41,
                'project_id' => 21,
                'employee_id' => 7,
            ],
        );
    }

    #[Test]
    public function management_pnl_group_coverage_rejects_a_missing_project_currency_pair(): void
    {
        $guard = new ManagementPnlSourceTupleGuard($this->createMock(ConnectionInterface::class));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('management_pnl_requested_group_coverage_gap');

        $guard->assertRequestedGroupCoverage(
            new Collection([
                (object) ['project_id' => 20, 'currency' => 'RUB'],
                (object) ['project_id' => 21, 'currency' => 'RUB'],
                (object) ['project_id' => 20, 'currency' => 'USD'],
            ]),
            [20, 21],
            ['RUB', 'USD'],
        );
    }

    #[Test]
    public function management_pnl_group_coverage_counts_requested_projects_per_currency(): void
    {
        $guard = new ManagementPnlSourceTupleGuard($this->createMock(ConnectionInterface::class));

        self::assertSame(
            [
                'projects' => [20, 21],
                'currencies' => ['RUB', 'USD'],
                'covered_by_currency' => ['RUB' => 2, 'USD' => 2],
                'denominator_per_currency' => 2,
            ],
            $guard->assertRequestedGroupCoverage(
                new Collection([
                    (object) ['project_id' => 20, 'currency' => 'RUB'],
                    (object) ['project_id' => 21, 'currency' => 'RUB'],
                    (object) ['project_id' => 20, 'currency' => 'USD'],
                    (object) ['project_id' => 21, 'currency' => 'USD'],
                ]),
                [21, 20],
                ['USD', 'RUB'],
            ),
        );
    }

    #[Test]
    public function management_pnl_source_run_uses_the_newest_active_exact_run_and_ignores_orphans(): void
    {
        $connection = $this->sourceRunConnection();
        $asOf = new DateTimeImmutable('2030-01-31T23:59:59+00:00');
        $clock = new FakeReportExecutionClock(new DateTimeImmutable('2030-02-01T00:00:00+00:00'));
        $scope = new ReportScope(10, [10], [20], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)
            ->code('project_labor_cost')
            ->formulaVersion('labor-cost.v1')
            ->sourceSchemaVersion('project-labor-cost-source.v1')
            ->published();
        $canonical = CanonicalJson::encode([
            'as_of' => $asOf->format(DATE_ATOM),
            'comparison' => [],
            'definition_hash' => $definition->definitionHash->value,
            'filters' => [
                'period_from' => '2030-01-01',
                'period_to' => '2030-01-31',
            ],
            'locale' => 'ru',
            'scope' => $scope->canonicalIdentity(),
        ]);
        $this->insertSourceRun(
            $connection,
            'expired-run',
            'expired-snapshot',
            $canonical,
            $definition->definitionHash->value,
            '2030-01-31T23:00:00+00:00',
            '2030-01-31T22:00:00+00:00',
        );
        $this->insertSourceRun(
            $connection,
            'older-active-run',
            'older-active-snapshot',
            $canonical,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:00:00+00:00',
        );
        $this->insertSourceRun(
            $connection,
            'current-active-run',
            'current-active-snapshot',
            $canonical,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:30:00+00:00',
        );

        $run = (new ManagementPnlSourceTupleGuard($connection, $clock))->selectActiveReadyRun(
            10,
            'project_labor_cost',
            'project_labor_cost',
            $definition,
            $scope,
            '2030-01-01',
            '2030-01-31',
            $asOf,
        );

        self::assertSame('current-active-run', $run->id);
        self::assertSame('current-active-snapshot', $run->snapshot_id);
    }

    #[Test]
    public function management_pnl_source_run_rejects_ready_rows_after_their_expiry(): void
    {
        $connection = $this->sourceRunConnection();
        $asOf = new DateTimeImmutable('2030-01-31T23:59:59+00:00');
        $clock = new FakeReportExecutionClock(new DateTimeImmutable('2030-02-01T00:00:00+00:00'));
        $scope = new ReportScope(10, [10], [20], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)
            ->code('payroll_readiness')
            ->formulaVersion('payroll-readiness.v1')
            ->sourceSchemaVersion('workforce-payroll-calculation.v1')
            ->published();
        $canonical = CanonicalJson::encode([
            'as_of' => $asOf->format(DATE_ATOM),
            'comparison' => [],
            'definition_hash' => $definition->definitionHash->value,
            'filters' => [
                'period_from' => '2030-01-01',
                'period_to' => '2030-01-31',
            ],
            'locale' => 'ru',
            'scope' => $scope->canonicalIdentity(),
        ]);
        $this->insertSourceRun(
            $connection,
            'expired-run',
            'expired-snapshot',
            $canonical,
            $definition->definitionHash->value,
            '2030-01-31T23:59:59+00:00',
            '2030-01-31T23:00:00+00:00',
            reportCode: 'payroll_readiness',
            snapshotKind: 'payroll_readiness',
            formulaVersion: 'payroll-readiness.v1',
            sourceSchemaVersion: 'workforce-payroll-calculation.v1',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('management_pnl_source_run_unavailable');

        (new ManagementPnlSourceTupleGuard($connection, $clock))->selectActiveReadyRun(
            10,
            'payroll_readiness',
            'payroll_readiness',
            $definition,
            $scope,
            '2030-01-01',
            '2030-01-31',
            $asOf,
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
            'calculation_version_id' => 3,
            'source_row_id' => 11,
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

    private function sourceRunConnection(): SQLiteConnection
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->statement(
            'CREATE TABLE report_runs (
                id TEXT PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                report_code TEXT NOT NULL,
                status TEXT NOT NULL,
                definition_hash TEXT NOT NULL,
                formula_version TEXT NOT NULL,
                source_schema_version TEXT NOT NULL,
                query_hash TEXT NOT NULL,
                canonical_query_json TEXT NOT NULL,
                source_hash TEXT NOT NULL,
                snapshot_kind TEXT NOT NULL,
                snapshot_id TEXT NOT NULL,
                as_of TEXT NOT NULL,
                ready_at TEXT NOT NULL,
                expires_at TEXT NOT NULL
            )',
        );

        return $connection;
    }

    private function insertSourceRun(
        SQLiteConnection $connection,
        string $id,
        string $snapshotId,
        string $canonical,
        string $definitionHash,
        string $expiresAt,
        string $readyAt,
        string $reportCode = 'project_labor_cost',
        string $snapshotKind = 'project_labor_cost',
        string $formulaVersion = 'labor-cost.v1',
        string $sourceSchemaVersion = 'project-labor-cost-source.v1',
    ): void {
        $connection->table('report_runs')->insert([
            'id' => $id,
            'organization_id' => 10,
            'report_code' => $reportCode,
            'status' => 'ready',
            'definition_hash' => $definitionHash,
            'formula_version' => $formulaVersion,
            'source_schema_version' => $sourceSchemaVersion,
            'query_hash' => hash('sha256', $canonical),
            'canonical_query_json' => $canonical,
            'source_hash' => str_repeat('b', 64),
            'snapshot_kind' => $snapshotKind,
            'snapshot_id' => $snapshotId,
            'as_of' => '2030-01-31 23:59:59+00:00',
            'ready_at' => $readyAt,
            'expires_at' => $expiresAt,
        ]);
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
