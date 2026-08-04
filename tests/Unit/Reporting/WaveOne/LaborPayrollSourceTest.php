<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
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
        self::assertContains(ReportDrillDownTokenColumns::class, class_implements(ProjectLaborCostQueryService::class));
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
            self::assertStringContainsString('selectActiveReadyTuple(', $source);
            self::assertStringContainsString('$sourceQuery = new ReportQuery(', $source);
            self::assertStringContainsString("->where('id', \$run->snapshot_id)", $source);
            self::assertStringContainsString("->where('query_hash', \$run->query_hash)", $source);
            self::assertStringContainsString("->where('source_hash', \$run->source_hash)", $source);
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
    public function management_pnl_source_tuple_skips_an_orphan_and_uses_the_next_active_sealed_run(): void
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
        $query = $this->sourceQuery($definition, $scope, $asOf);
        $this->insertSourceRun(
            $connection,
            'expired-run',
            'expired-snapshot',
            $query->canonicalJson,
            $definition->definitionHash->value,
            '2030-01-31T23:00:00+00:00',
            '2030-01-31T22:00:00+00:00',
        );
        $this->insertSourceRun(
            $connection,
            'older-active-run',
            'older-active-snapshot',
            $query->canonicalJson,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:00:00+00:00',
        );
        $this->insertSourceRun(
            $connection,
            'current-active-run',
            'missing-snapshot',
            $query->canonicalJson,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:30:00+00:00',
        );

        $tuple = (new ManagementPnlSourceTupleGuard($connection, $clock))->selectActiveReadyTuple(
            10,
            'project_labor_cost',
            'project_labor_cost',
            $definition,
            $query,
            'time_tracking_owner_snapshot',
            fn (object $run): ?object => $run->snapshot_id === 'older-active-snapshot'
                ? $this->sourceSnapshot($run)
                : null,
        );

        self::assertSame('older-active-run', $tuple->run->id);
        self::assertSame('older-active-snapshot', $tuple->snapshot->id);
    }

    #[Test]
    public function management_pnl_source_tuple_requires_the_exact_canonical_query_including_currency_and_locale(): void
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
        $expected = $this->sourceQuery($definition, $scope, $asOf, ['RUB'], 'ru-RU');
        $different = $this->sourceQuery($definition, $scope, $asOf, ['USD'], 'en-US');
        $this->insertSourceRun(
            $connection,
            'older-exact-run',
            'older-exact-snapshot',
            $expected->canonicalJson,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:00:00+00:00',
        );
        $this->insertSourceRun(
            $connection,
            'newer-different-run',
            'newer-different-snapshot',
            $different->canonicalJson,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:30:00+00:00',
        );

        $tuple = (new ManagementPnlSourceTupleGuard($connection, $clock))->selectActiveReadyTuple(
            10,
            'project_labor_cost',
            'project_labor_cost',
            $definition,
            $expected,
            'time_tracking_owner_snapshot',
            fn (object $run): object => $this->sourceSnapshot($run),
        );

        self::assertSame('older-exact-run', $tuple->run->id);
    }

    #[Test]
    public function management_pnl_source_tuple_skips_a_run_with_mismatched_sealed_lineage(): void
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
        $query = $this->sourceQuery($definition, $scope, $asOf);
        $this->insertSourceRun(
            $connection,
            'older-valid-run',
            'older-valid-snapshot',
            $query->canonicalJson,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:00:00+00:00',
        );
        $this->insertSourceRun(
            $connection,
            'newer-invalid-lineage-run',
            'newer-invalid-lineage-snapshot',
            $query->canonicalJson,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:30:00+00:00',
            rowCount: 99,
        );

        $tuple = (new ManagementPnlSourceTupleGuard($connection, $clock))->selectActiveReadyTuple(
            10,
            'project_labor_cost',
            'project_labor_cost',
            $definition,
            $query,
            'time_tracking_owner_snapshot',
            fn (object $run): object => $this->sourceSnapshot($run),
        );

        self::assertSame('older-valid-run', $tuple->run->id);
    }

    #[Test]
    public function management_pnl_source_tuple_accepts_a_real_payroll_manifest_with_domain_metadata(): void
    {
        $connection = $this->sourceRunConnection();
        $clock = new FakeReportExecutionClock(new DateTimeImmutable('2030-02-01T00:00:00+00:00'));
        $scope = new ReportScope(10, [10], [20], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)
            ->code('payroll_readiness')
            ->formulaVersion('payroll-readiness.v1')
            ->sourceSchemaVersion('workforce-payroll-calculation.v1')
            ->published();
        $query = $this->sourceQuery(
            $definition,
            $scope,
            new DateTimeImmutable('2030-01-31T23:59:59+00:00'),
        );
        $this->insertSourceRun(
            $connection,
            'payroll-run',
            'payroll-snapshot',
            $query->canonicalJson,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:30:00+00:00',
            reportCode: 'payroll_readiness',
            snapshotKind: 'payroll_readiness',
            formulaVersion: 'payroll-readiness.v1',
            sourceSchemaVersion: 'workforce-payroll-calculation.v1',
        );

        $tuple = (new ManagementPnlSourceTupleGuard($connection, $clock))->selectActiveReadyTuple(
            10,
            'payroll_readiness',
            'payroll_readiness',
            $definition,
            $query,
            'locked_payroll_calculation',
            function (object $run): object {
                $snapshot = $this->sourceSnapshot($run);
                $sourceRefs = $this->sourceRefs();
                $sourceRefs[0]['payroll_period_id'] = 41;
                $snapshot->source_refs = CanonicalJson::encode($sourceRefs);

                return $snapshot;
            },
        );

        self::assertSame('payroll-run', $tuple->run->id);
        self::assertSame('payroll-snapshot', $tuple->snapshot->id);
    }

    #[Test]
    public function management_pnl_source_tuple_propagates_snapshot_loader_errors(): void
    {
        $connection = $this->sourceRunConnection();
        $clock = new FakeReportExecutionClock(new DateTimeImmutable('2030-02-01T00:00:00+00:00'));
        $scope = new ReportScope(10, [10], [20], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)
            ->code('project_labor_cost')
            ->formulaVersion('labor-cost.v1')
            ->sourceSchemaVersion('project-labor-cost-source.v1')
            ->published();
        $query = $this->sourceQuery(
            $definition,
            $scope,
            new DateTimeImmutable('2030-01-31T23:59:59+00:00'),
        );
        $this->insertSourceRun(
            $connection,
            'loader-error-run',
            'loader-error-snapshot',
            $query->canonicalJson,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:30:00+00:00',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('snapshot_loader_failed');

        (new ManagementPnlSourceTupleGuard($connection, $clock))->selectActiveReadyTuple(
            10,
            'project_labor_cost',
            'project_labor_cost',
            $definition,
            $query,
            'time_tracking_owner_snapshot',
            static fn (object $run): never => throw new \RuntimeException('snapshot_loader_failed'),
        );
    }

    #[Test]
    public function management_pnl_source_tuple_rejects_every_mismatched_sealed_lineage_field(): void
    {
        $connection = $this->sourceRunConnection();
        $clock = new FakeReportExecutionClock(new DateTimeImmutable('2030-02-01T00:00:00+00:00'));
        $scope = new ReportScope(10, [10], [20], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)
            ->code('project_labor_cost')
            ->formulaVersion('labor-cost.v1')
            ->sourceSchemaVersion('project-labor-cost-source.v1')
            ->published();
        $query = $this->sourceQuery(
            $definition,
            $scope,
            new DateTimeImmutable('2030-01-31T23:59:59+00:00'),
        );
        $this->insertSourceRun(
            $connection,
            'sealed-run',
            'sealed-snapshot',
            $query->canonicalJson,
            $definition->definitionHash->value,
            '2030-02-02T00:00:00+00:00',
            '2030-01-31T23:30:00+00:00',
        );
        $run = $connection->table('report_runs')->where('id', 'sealed-run')->first();
        self::assertNotNull($run);
        $snapshot = $this->sourceSnapshot($run);
        $mismatches = [
            'generated_at' => static function (object $run, object $snapshot): void {
                $snapshot->generated_at = '2030-01-31T23:44:59+00:00';
            },
            'row_count' => static function (object $run, object $snapshot): void {
                $run->row_count = 3;
            },
            'freshness' => static function (object $run, object $snapshot): void {
                $snapshot->freshness_status = 'stale';
            },
            'stale_at' => static function (object $run, object $snapshot): void {
                $snapshot->stale_at = '2030-02-03T00:00:00+00:00';
            },
            'watermarks' => static function (object $run, object $snapshot): void {
                $run->snapshot_watermarks = CanonicalJson::encode(['rows_time_entry' => 'max_id_3']);
            },
            'provenance' => static function (object $run, object $snapshot): void {
                $run->provenance = CanonicalJson::encode([
                    'source_of_truth' => 'another_source',
                    'source_refs' => [],
                    'source_hash' => str_repeat('b', 64),
                    'external_confirmation_role' => null,
                ]);
            },
        ];
        $guard = new ManagementPnlSourceTupleGuard($connection, $clock);

        foreach ($mismatches as $field => $mutate) {
            $candidateRun = clone $run;
            $candidateSnapshot = clone $snapshot;
            $mutate($candidateRun, $candidateSnapshot);
            try {
                $guard->assertRunSnapshotTuple(
                    $candidateRun,
                    'project_labor_cost',
                    $candidateSnapshot,
                    $definition,
                    'time_tracking_owner_snapshot',
                );
                self::fail('Accepted mismatched sealed lineage field: '.$field);
            } catch (DomainException $exception) {
                self::assertSame('management_pnl_source_run_tuple_unsealed', $exception->getMessage(), $field);
            }
        }
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
        $query = $this->sourceQuery($definition, $scope, $asOf);
        $this->insertSourceRun(
            $connection,
            'expired-run',
            'expired-snapshot',
            $query->canonicalJson,
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

        (new ManagementPnlSourceTupleGuard($connection, $clock))->selectActiveReadyTuple(
            10,
            'payroll_readiness',
            'payroll_readiness',
            $definition,
            $query,
            'locked_payroll_calculation',
            fn (object $run): object => $this->sourceSnapshot($run),
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
                snapshot_generated_at TEXT NOT NULL,
                snapshot_stale_at TEXT NULL,
                snapshot_watermarks TEXT NOT NULL,
                row_count INTEGER NOT NULL,
                freshness TEXT NOT NULL,
                provenance TEXT NOT NULL,
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
        int $rowCount = 2,
    ): void {
        $sourceRefs = $this->sourceRefs();
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
            'snapshot_generated_at' => '2030-01-31T23:45:00+00:00',
            'snapshot_stale_at' => '2030-02-02T00:00:00+00:00',
            'snapshot_watermarks' => CanonicalJson::encode([
                'rows_time_entry' => 'max_id_2',
            ]),
            'row_count' => $rowCount,
            'freshness' => 'fresh',
            'provenance' => CanonicalJson::encode([
                'source_of_truth' => $reportCode === 'payroll_readiness'
                    ? 'locked_payroll_calculation'
                    : 'time_tracking_owner_snapshot',
                'source_refs' => $sourceRefs,
                'source_hash' => str_repeat('b', 64),
                'external_confirmation_role' => null,
            ]),
            'as_of' => '2030-01-31 23:59:59+00:00',
            'ready_at' => $readyAt,
            'expires_at' => $expiresAt,
        ]);
    }

    private function sourceQuery(
        PublishedReportDefinition $definition,
        ReportScope $scope,
        DateTimeImmutable $asOf,
        array $currencies = ['RUB'],
        string $locale = 'ru-RU',
    ): ReportQuery {
        return new ReportQuery(
            $definition->definition,
            $scope,
            new ReportFilterSet([
                'currencies' => $currencies,
                'period_from' => '2030-01-01',
                'period_to' => '2030-01-31',
                'scenarios' => ['actual'],
            ]),
            [],
            $asOf,
            $locale,
        );
    }

    private function sourceSnapshot(object $run): object
    {
        return (object) [
            'id' => (string) $run->snapshot_id,
            'query_hash' => (string) $run->query_hash,
            'source_hash' => str_repeat('b', 64),
            'generated_at' => '2030-01-31T23:45:00+00:00',
            'stale_at' => '2030-02-02T00:00:00+00:00',
            'freshness_status' => 'fresh',
            'source_refs' => CanonicalJson::encode($this->sourceRefs()),
            'row_count' => 2,
        ];
    }

    private function sourceRefs(): array
    {
        return [[
            'source' => 'time_entries',
            'snapshot_kind' => 'temporal_owner_facts',
            'snapshot_id' => 'rows_time_entry',
            'schema_version' => 'v1',
            'watermark' => 'max_id_2',
            'row_count' => 2,
            'hash' => str_repeat('a', 64),
        ]];
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
