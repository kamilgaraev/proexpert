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
    public function accepted_production_reversal_and_delete_are_transactional_and_never_backfill_from_act_date(): void
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
        self::assertStringContainsString('$recognizedAt = $act->signed_at;', $backfill);
        self::assertStringNotContainsString('$act->approval_date', $backfill);
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
