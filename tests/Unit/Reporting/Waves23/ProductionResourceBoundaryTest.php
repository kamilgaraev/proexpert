<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionResourceBoundaryTest extends TestCase
{
    #[Test]
    public function accepted_production_filters_resources_before_hash_totals_and_rows(): void
    {
        $source = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionSnapshotMaterializer.php',
        );

        $filterPosition = strpos($source, '$allEvents = $completeEvents');
        $hashPosition = strpos($source, '$sourceHash = new Sha256Hash');
        $factsPosition = strpos($source, '$facts = $events');

        self::assertIsInt($filterPosition);
        self::assertIsInt($hashPosition);
        self::assertIsInt($factsPosition);
        self::assertLessThan($hashPosition, $filterPosition);
        self::assertLessThan($factsPosition, $filterPosition);
        self::assertStringContainsString("['work', 'completed_work']", $source);
        self::assertStringContainsString("'type' => 'performance_act'", $source);
        self::assertStringContainsString("'type' => 'completed_work'", $source);
    }

    #[Test]
    public function project_control_filters_task_scope_and_persists_mandatory_task_reference(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Budgeting/Reporting/ProjectControl/Services/'
            .'ProjectControlSourceAssembler.php',
        );

        self::assertStringContainsString("['task', 'schedule_task']", $source);
        self::assertStringContainsString(
            '$scopedTaskIds !== null && ! in_array($taskId, $scopedTaskIds, true)',
            $source,
        );
        self::assertStringContainsString("'type' => 'schedule_task'", $source);
        self::assertStringContainsString("'project_id' => \$projectId", $source);
    }

    #[Test]
    public function baseline_capture_serializes_stream_and_migration_drops_dependencies_first(): void
    {
        $service = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/'
            .'BaselineScheduleSnapshotService.php',
        );
        $migration = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/migrations/'
            .'2026_07_26_000130_create_schedule_baseline_report_snapshots.php',
        );

        self::assertStringContainsString('pg_advisory_xact_lock', $service);
        self::assertStringContainsString("orderByDesc('version')", $service);
        self::assertStringNotContainsString("->lockForUpdate()\n                ->max('version')", $service);
        self::assertStringContainsString("->where('captured_at', \$capturedAt)", $service);
        self::assertStringContainsString('$duplicate instanceof ScheduleBaselineVersion', $service);

        $dropTaskRows = strpos($migration, "Schema::dropIfExists('schedule_baseline_task_rows')");
        $dropVersions = strpos($migration, "Schema::dropIfExists('schedule_baseline_versions')");
        $dropFunction = strpos(
            $migration,
            "DB::statement('DROP FUNCTION IF EXISTS schedule_reporting_history_append_only_guard()')",
        );
        self::assertIsInt($dropTaskRows);
        self::assertIsInt($dropVersions);
        self::assertIsInt($dropFunction);
        self::assertLessThan($dropFunction, $dropTaskRows);
        self::assertLessThan($dropFunction, $dropVersions);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).DIRECTORY_SEPARATOR.$relativePath);
        self::assertIsString($source);

        return $source;
    }
}
