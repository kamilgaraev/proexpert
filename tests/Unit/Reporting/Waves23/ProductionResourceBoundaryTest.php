<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionResourceBoundaryTest extends TestCase
{
    #[Test]
    public function modular_gate_includes_postgresql_feature_contracts(): void
    {
        $configuration = $this->source('phpunit.waves23.xml');

        self::assertStringContainsString(
            '<directory>tests/Feature/Reporting/Waves23</directory>',
            $configuration,
        );
    }

    #[Test]
    public function accepted_production_filters_resources_before_hash_totals_and_rows(): void
    {
        $source = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionSnapshotMaterializer.php',
        );

        $filterPosition = strpos($source, '$stream = $this->universe->stream($scope, $query)');
        $hashPosition = strpos($source, '$sourceHash = new Sha256Hash');

        self::assertIsInt($filterPosition);
        self::assertIsInt($hashPosition);
        self::assertLessThan($hashPosition, $filterPosition);
        self::assertStringNotContainsString('$facts = $events', $source);
        self::assertStringContainsString('foreach ($stream->entries() as $entry)', $source);
        $universe = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionEventUniverse.php',
        );
        self::assertStringContainsString("['work', 'completed_work']", $universe);
        self::assertStringContainsString("'type' => 'performance_act'", $source);
        self::assertStringContainsString("'type' => 'completed_work'", $source);
    }

    #[Test]
    public function accepted_production_readiness_and_materialization_share_the_same_filtered_universe(): void
    {
        $readiness = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Readiness/'
            .'AcceptedProductionReadinessProbe.php',
        );
        $materializer = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionSnapshotMaterializer.php',
        );
        $universe = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionEventUniverse.php',
        );

        self::assertStringContainsString('$this->universe->stream($context->scope, $query)', $readiness);
        self::assertStringContainsString('$this->universe->stream($scope, $query)', $materializer);
        self::assertStringContainsString('ProductionAcceptanceOwnerVersion::query()', $universe);
        self::assertStringContainsString('$ownerQuery->lazyById(100)', $universe);
        self::assertStringContainsString('foreach ($eventQuery->cursor() as $event)', $universe);
        self::assertStringNotContainsString('$eventsByKey', $universe);
        self::assertTrue(
            strpos($universe, '$ownerQuery = $this->ownerQuery(')
                < strpos($universe, '$eventQuery = ProductionAcceptanceEvent::query()'),
        );
        self::assertStringContainsString('$stream->gapCount()', $readiness);
        self::assertStringContainsString('$stream->gapCount()', $materializer);
        self::assertStringNotContainsString('$completeEvents = ProductionAcceptanceEvent::query()', $materializer);
    }

    #[Test]
    public function accepted_production_spools_a_stable_stream_and_never_accumulates_all_rows(): void
    {
        $universe = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionEventUniverse.php',
        );
        $stream = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/DTO/'
            .'AcceptedProductionUniverseStream.php',
        );
        $materializer = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionSnapshotMaterializer.php',
        );
        $readiness = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Readiness/'
            .'AcceptedProductionReadinessProbe.php',
        );
        $migration = $this->source(
            'database/migrations/2026_07_26_100000_create_accepted_production_reporting_tables.php',
        );
        $writer = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'ProductionAcceptanceOwnerVersionWriter.php',
        );

        self::assertStringContainsString('new DeterministicObjectSpool', $universe);
        self::assertStringContainsString('->chunkById(500, function ($memberPage)', $universe);
        $streamPath = substr($universe, 0, (int) strpos($universe, 'public function resolve('));
        self::assertStringNotContainsString('foreach ((array) $owner->members', $streamPath);
        self::assertStringContainsString(
            "Schema::create('production_acceptance_owner_members'",
            $migration,
        );
        self::assertStringContainsString('production_acceptance_owner_members_append_only', $migration);
        self::assertStringContainsString('array_chunk($members, 500)', $writer);
        self::assertStringContainsString('updateCanonicalArrayHash($context)', $stream);
        self::assertStringContainsString('new DeterministicReadinessAccumulator', $readiness);
        self::assertStringNotContainsString('$eligible = []', $readiness);
        self::assertStringNotContainsString('$projected = []', $readiness);
        self::assertStringNotContainsString('$rows = $facts->map', $materializer);
        self::assertStringContainsString(
            "DB::table('accepted_production_rows')->insert(\$rowBatch)",
            $materializer,
        );
    }

    #[Test]
    public function accepted_production_owner_membership_is_versioned_and_append_only(): void
    {
        $migration = $this->source(
            'database/migrations/2026_07_26_100000_create_accepted_production_reporting_tables.php',
        );
        $writer = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'ProductionAcceptanceOwnerVersionWriter.php',
        );
        $recorder = $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'ProductionAcceptanceEventRecorder.php',
        );

        self::assertStringContainsString(
            "Schema::create('production_acceptance_owner_versions'",
            $migration,
        );
        self::assertStringContainsString('production_acceptance_owner_versions_append_only', $migration);
        self::assertStringContainsString("->where('effective_at', '<=', \$query->asOf)", $this->source(
            'app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'AcceptedProductionEventUniverse.php',
        ));
        self::assertStringContainsString('pg_advisory_xact_lock', $writer);
        self::assertLessThan(
            strpos($recorder, '$eventIds = [];'),
            strpos($recorder, '$this->ownerVersions->record('),
        );
    }

    #[Test]
    public function lookahead_filters_constraints_before_selecting_policy_projects(): void
    {
        $readiness = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Readiness/'
            .'LookaheadReadinessProbe.php',
        );
        $materializer = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Services/'
            .'LookaheadReadinessSnapshotMaterializer.php',
        );

        self::assertLessThan(
            strpos($readiness, '$effectiveProjectIds = array_keys'),
            strpos($readiness, '$filtered = $this->filterConstraints'),
        );
        self::assertLessThan(
            strpos($readiness, '$this->policies->activeForProjects'),
            strpos($readiness, '$effectiveProjectIds = array_keys'),
        );
        self::assertLessThan(
            strpos($materializer, '$effectiveProjectIds = array_keys'),
            strpos($materializer, '$constraints = $this->filterConstraints'),
        );
        self::assertStringContainsString(
            '$policySet = $effectiveProjectIds === []',
            $materializer,
        );
    }

    #[Test]
    public function lookahead_materializer_reduces_history_in_bounded_pages_before_spooling(): void
    {
        $materializer = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Services/'
            .'LookaheadReadinessSnapshotMaterializer.php',
        );
        $history = $this->source(
            'app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Services/'
            .'LookaheadConstraintHistoryStream.php',
        );
        $beforeSpool = substr(
            $materializer,
            0,
            (int) strpos($materializer, '$inputSpool = new DeterministicObjectSpool'),
        );

        self::assertStringNotContainsString('->get(', $beforeSpool);
        self::assertStringNotContainsString('->pluck(', $beforeSpool);
        self::assertStringNotContainsString('->groupBy(', $beforeSpool);
        self::assertStringContainsString('$states->chunk(100)', $materializer);
        self::assertStringContainsString('->whereNotExists(', $materializer);
        self::assertStringContainsString('->cursor()', $history);
        self::assertStringContainsString('new LookaheadConstraintHistoryReducer', $history);
        self::assertStringNotContainsString('$lineage = []', $history);
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
        self::assertStringContainsString('foreach ($duplicateQuery->cursor() as $candidate)', $service);
        self::assertStringContainsString('new DeterministicObjectSpool', $service);
        self::assertStringContainsString(
            "DB::table('schedule_baseline_task_rows')->insert(\$rowBatch)",
            $service,
        );
        self::assertStringContainsString(
            "DB::table('baseline_schedule_variance_rows')->insert(\$rowBatch)",
            $service,
        );
        foreach (['->pluck(', '->get(', '->groupBy('] as $unboundedOperation) {
            self::assertStringNotContainsString($unboundedOperation, $service);
        }

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
