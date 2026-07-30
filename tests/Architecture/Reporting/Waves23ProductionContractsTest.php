<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Waves23ProductionContractsTest extends TestCase
{
    #[Test]
    public function r06_formula_and_historical_state_contract_are_exact(): void
    {
        $service = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/BaselineScheduleSnapshotService.php'
        );
        $migration = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/migrations/'
            .'2026_07_26_000130_create_schedule_baseline_report_snapshots.php'
        );

        self::assertStringContainsString("'schedule.baseline-variance.v1'", $service);
        self::assertStringNotContainsString("'schedule.baseline_variance.v1'", $service);
        self::assertStringContainsString("Schema::create('schedule_task_state_versions'", $migration);
        self::assertStringContainsString("where('effective_at', '<=', \$asOf)", $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/HistoricalScheduleTaskStateQuery.php'
        ));
    }

    #[Test]
    public function accepted_production_reversal_and_backfill_use_canonical_owner_timestamps(): void
    {
        $service = $this->source('app/Services/Contract/ContractPerformanceActService.php');
        $observer = $this->source('app/Observers/ContractPerformanceActObserver.php');
        $backfill = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/AcceptedProductionBackfill.php'
        );
        $recorder = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'ProductionAcceptanceEventRecorder.php'
        );
        $migration = $this->source(
            'database/migrations/2026_07_26_100000_create_accepted_production_reporting_tables.php',
        );

        self::assertStringContainsString(
            "DB::transaction(\n            fn (): bool => \$this->actRepository->delete(\$actId)",
            $service,
        );
        self::assertStringContainsString('DB::transactionLevel() < 1', $observer);
        self::assertStringContainsString(
            '$recognizedAt = $act->signed_at ?? $act->approval_date?->toImmutable()->startOfDay();',
            $backfill,
        );
        self::assertStringNotContainsString('CarbonImmutable::now()', $backfill);
        self::assertStringContainsString('$this->reversals->fromAccepted($acceptedEvent)', $recorder);
        self::assertStringContainsString("'approved_rate_minor' => \$approvedRate->minor", $recorder);
        self::assertStringContainsString(
            'production_acceptance_events_append_only',
            $migration,
        );
        self::assertStringContainsString(
            'BEFORE UPDATE OR DELETE ON production_acceptance_events',
            $migration,
        );
    }

    #[Test]
    public function accepted_production_historical_completeness_never_uses_events_after_as_of(): void
    {
        $readiness = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Readiness/'
            .'AcceptedProductionReadinessProbe.php',
        );
        $materializer = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionSnapshotMaterializer.php',
        );

        self::assertStringNotContainsString('$laterKeys', $readiness);
        self::assertStringNotContainsString('$laterEventKeys', $materializer);
        self::assertStringContainsString("where('recognized_at', '<=', \$query->asOf)", $readiness);
        self::assertStringContainsString("where('recognized_at', '<=', \$query->asOf)", $materializer);
    }

    #[Test]
    public function schedule_readiness_uses_the_same_query_filters_as_materialization(): void
    {
        $baseline = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Readiness/'
            .'BaselineScheduleVarianceReadinessProbe.php',
        );
        $lookahead = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Readiness/'
            .'LookaheadReadinessProbe.php',
        );

        foreach (['project_ids', 'schedule_ids', 'task_ids', 'wbs_ids', 'owner_ids', 'contractor_ids', 'statuses', 'critical'] as $filter) {
            self::assertStringContainsString("'{$filter}'", $baseline);
        }
        foreach (['project_ids', 'horizon_days', 'zone_ids', 'wbs_ids', 'owner_ids', 'contractor_ids', 'task_statuses', 'constraint_types', 'severities', 'statuses'] as $filter) {
            self::assertStringContainsString("'{$filter}'", $lookahead);
        }
        self::assertStringContainsString('HistoricalScheduleTaskStateQuery', $baseline);
        self::assertStringContainsString('HistoricalScheduleTaskStateQuery', $lookahead);
        self::assertStringContainsString(
            '$state->active && $this->matchesTaskFilters($query, $state)',
            $baseline,
        );
        self::assertStringContainsString(
            "\$this->positiveIntegerFilter(\$query, 'project_ids') ?: \$context->scope->projectIds",
            $baseline,
        );
        self::assertStringContainsString(
            "'state:'.hash('sha256', implode('|', \$states->pluck('sourceHash')->all()))",
            $baseline,
        );
        self::assertStringNotContainsString("\$states->max('id')", $baseline);
        self::assertStringNotContainsString('$events->count() < 2', $lookahead);
        self::assertStringContainsString(
            "->where('occurred_at', '<=', \$query->asOf)\n                ->max('id')",
            $lookahead,
        );
        self::assertStringContainsString(
            "throw new InvalidArgumentException('lookahead_horizon_filter_invalid')",
            $lookahead,
        );
        self::assertSame(
            2,
            substr_count(
                $baseline.$this->source(
                    'app/BusinessModules/Features/ScheduleManagement/Reporting/'
                    .'BaselineScheduleSnapshotService.php',
                ),
                'static fn (int $value): bool => $value < 1',
            ),
        );
        self::assertSame(
            2,
            substr_count(
                $lookahead.$this->source(
                    'app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Services/'
                    .'LookaheadReadinessSnapshotMaterializer.php',
                ),
                'static fn (int $value): bool => $value < 1',
            ),
        );
    }

    #[Test]
    public function accepted_production_readiness_uses_latest_immutable_lifecycle_event(): void
    {
        $readiness = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Readiness/'
            .'AcceptedProductionReadinessProbe.php',
        );
        $lifecycle = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionLifecycleCompleteness.php',
        );

        self::assertStringContainsString('->groupBy(static fn (ProductionAcceptanceEvent $event)', $lifecycle);
        self::assertStringContainsString("\$latest->event_type !== 'reversed'", $lifecycle);
        self::assertStringContainsString('$this->completeness->inspect(', $readiness);
        self::assertStringNotContainsString('$eventKeys->has($key)', $readiness);
    }

    #[Test]
    public function accepted_production_completeness_uses_temporal_owner_history_instead_of_current_status(): void
    {
        $lifecycle = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionLifecycleCompleteness.php',
        );
        $readiness = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Readiness/'
            .'AcceptedProductionReadinessProbe.php',
        );
        $materializer = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionSnapshotMaterializer.php',
        );

        self::assertStringContainsString("where('signed_at', '<=', \$asOf)", $lifecycle);
        self::assertStringContainsString("where('approval_date', '<=', \$asOf)", $lifecycle);
        self::assertStringNotContainsString("whereNull('signed_at')", $lifecycle);
        self::assertStringContainsString('rejected_at', $lifecycle);
        self::assertStringContainsString('accepted_production_owner_history_unproven', $lifecycle);
        self::assertStringNotContainsString("where('is_approved'", $lifecycle);
        self::assertStringNotContainsString("orWhereIn('status'", $lifecycle);
        self::assertStringContainsString('$this->completeness->inspect(', $readiness);
        self::assertStringContainsString('$this->completeness->assertComplete(', $materializer);
    }

    #[Test]
    public function production_quality_is_derived_from_the_persisted_snapshot_envelope(): void
    {
        $reader = $this->source('app/Support/Reporting/ImmutableOwnerProjectionReader.php');

        self::assertStringContainsString(
            '$snapshotTotals = (array) $snapshotRecord->getAttribute(\'totals\')',
            $reader,
        );
        self::assertStringContainsString('quality: $this->quality($snapshotTotals)', $reader);
        self::assertStringNotContainsString('quality: $this->quality($rows)', $reader);
    }

    #[Test]
    public function schedule_candidate_universe_is_identical_and_historical_for_r06_and_r07(): void
    {
        $baselineReadiness = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Readiness/'
            .'BaselineScheduleVarianceReadinessProbe.php',
        );
        $baselineMaterializer = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/'
            .'BaselineScheduleSnapshotService.php',
        );
        $lookaheadReadiness = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Readiness/'
            .'LookaheadReadinessProbe.php',
        );
        $lookaheadMaterializer = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Services/'
            .'LookaheadReadinessSnapshotMaterializer.php',
        );

        self::assertStringContainsString(
            "throw new InvalidArgumentException('lookahead_project_filter_empty')",
            $lookaheadReadiness,
        );
        self::assertStringContainsString(
            "throw new InvalidArgumentException('lookahead_project_filter_empty')",
            $lookaheadMaterializer,
        );
        foreach ([
            $baselineReadiness,
            $baselineMaterializer,
            $lookaheadReadiness,
            $lookaheadMaterializer,
        ] as $source) {
            self::assertStringContainsString("where('created_at', '<=', \$query->asOf)", $source);
        }
    }

    #[Test]
    public function every_r05_r08_drilldown_enforces_source_object_access(): void
    {
        foreach ([
            'app/BusinessModules/Features/Budgeting/Reporting/ProjectControl/DrillDown/'
            .'ProjectEvmControlDrillDownProvider.php',
            'app/BusinessModules/Features/ScheduleManagement/Reporting/'
            .'BaselineScheduleVarianceQueryService.php',
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/DrillDown/'
            .'LookaheadReadinessDrillDownProvider.php',
            'app/Services/CompletedWork/Reporting/AcceptedProduction/DrillDown/'
            .'AcceptedProductionDrillDownProvider.php',
        ] as $path) {
            $source = $this->source($path);
            self::assertStringContainsString('ReportSourceObjectAccessAuthorizer', $source);
            self::assertStringContainsString('assertAccessible(', $source);
        }
    }

    #[Test]
    public function historical_backfills_never_recreate_missing_baselines_from_mutable_current_rows(): void
    {
        $projectControl = $this->source(
            'app/BusinessModules/Features/Budgeting/Reporting/ProjectControl/Backfill/'
            .'ProjectControlCoreBackfill.php',
        );
        $scheduleVariance = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/'
            .'ScheduleBaselineVersionBackfill.php',
        );
        $scheduleReadiness = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Readiness/'
            .'BaselineScheduleVarianceReadinessProbe.php',
        );

        self::assertStringNotContainsString('CaptureScheduleBaselineVersion', $projectControl);
        self::assertStringNotContainsString('$this->capture->capture(', $projectControl);
        self::assertStringContainsString(
            "if (\$existing === null) {\n                \$gapCount++;",
            $projectControl,
        );
        self::assertStringContainsString(
            "if (\$baseline === null) {\n                \$gapCount++;",
            $scheduleVariance,
        );
        self::assertStringContainsString(
            'foreach ($schedules as $schedule)',
            $scheduleReadiness,
        );
        self::assertStringContainsString(
            "if (\$baseline === null) {\n                \$gapCount++;",
            $scheduleReadiness,
        );
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($source);

        return $source;
    }
}
