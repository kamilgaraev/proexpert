<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedProductionHistoryBoundaryContractTest extends TestCase
{
    #[Test]
    public function migration_creates_a_truthful_boundary_without_reconstructing_legacy_events(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 3)
            .'/database/migrations/2026_08_06_000150_create_production_acceptance_history_checkpoints.php',
        );

        self::assertIsString($migration);
        self::assertStringContainsString(
            "Schema::create('production_acceptance_history_checkpoints'",
            $migration,
        );
        self::assertStringContainsString(
            'LOCK TABLE organizations, contracts, contract_performance_acts, '
            .'production_acceptance_owner_versions, production_acceptance_owner_members, '
            .'production_acceptance_events, production_acceptance_backfill_ledger',
            $migration,
        );
        self::assertStringContainsString('clock_timestamp()::timestamptz(6)', $migration);
        self::assertStringContainsString('excluded_legacy_act_count', $migration);
        self::assertStringContainsString('performance_act_watermark_id', $migration);
        self::assertStringContainsString('owner_version_watermark_id', $migration);
        self::assertStringContainsString('owner_member_watermark_id', $migration);
        self::assertStringContainsString('event_watermark_id', $migration);
        self::assertStringContainsString('backfill_ledger_watermark_id', $migration);
        self::assertStringContainsString('legacy_act_set_hash', $migration);
        self::assertStringContainsString('owner_version_set_hash', $migration);
        self::assertStringContainsString('owner_member_set_hash', $migration);
        self::assertStringContainsString('event_set_hash', $migration);
        self::assertStringContainsString('backfill_ledger_set_hash', $migration);
        self::assertStringContainsString('owner_version_count', $migration);
        self::assertStringContainsString('owner_member_count', $migration);
        self::assertStringContainsString('event_count', $migration);
        self::assertStringContainsString(
            'production_acceptance_history_checkpoints_append_only',
            $migration,
        );
        self::assertStringContainsString(
            'production_acceptance_owner_members_scope_guard',
            $migration,
        );
        self::assertStringContainsString('owner member scope drift detected', $migration);
        self::assertStringContainsString(
            'most_seed_production_acceptance_history_checkpoint_v1',
            $migration,
        );
        self::assertStringContainsString(
            'report_accepted_production_history_boundary_completed',
            $migration,
        );
        self::assertStringContainsString('$evidence = DB::transaction', $migration);
        self::assertStringContainsString('DB::afterCommit', $migration);
        self::assertGreaterThan(
            strpos($migration, 'DB::afterCommit'),
            strpos($migration, "Log::info('report_accepted_production_history_boundary_completed'"),
        );
        self::assertStringContainsString('checkpoint organization coverage mismatch', $migration);
        self::assertStringNotContainsString('INSERT INTO production_acceptance_events', $migration);
        self::assertStringNotContainsString('INSERT INTO production_acceptance_owner_versions', $migration);
        self::assertStringNotContainsString('AcceptedProductionBackfill', $migration);
        self::assertStringNotContainsString('ProductionAcceptanceEventRecorder', $migration);
    }

    #[Test]
    public function checkpoint_model_is_append_only_by_convention(): void
    {
        $model = file_get_contents(
            dirname(__DIR__, 3)
            .'/app/Services/CompletedWork/Reporting/AcceptedProduction/Models/'
            .'ProductionAcceptanceHistoryCheckpoint.php',
        );

        self::assertIsString($model);
        self::assertStringContainsString(
            "protected \$table = 'production_acceptance_history_checkpoints';",
            $model,
        );
        self::assertStringContainsString('public $timestamps = false;', $model);
        self::assertStringContainsString("'completed_at' => 'immutable_datetime'", $model);
        self::assertStringContainsString(
            'production_acceptance_history_checkpoint_immutable',
            $model,
        );
    }

    #[Test]
    public function every_production_act_workflow_records_acceptance_boundaries(): void
    {
        $adminWorkflow = file_get_contents(
            dirname(__DIR__, 3).'/app/Services/ActReport/ActReportWorkflowService.php',
        );
        $contractWorkflow = file_get_contents(
            dirname(__DIR__, 3).'/app/Services/Contract/ContractPerformanceActService.php',
        );

        self::assertIsString($adminWorkflow);
        self::assertIsString($contractWorkflow);
        self::assertSame(3, substr_count($adminWorkflow, 'recordTransitionIfApplicable('));
        self::assertGreaterThanOrEqual(5, substr_count($adminWorkflow, '->lockForUpdate()'));
        self::assertSame(2, substr_count($contractWorkflow, 'recordTransitionIfApplicable('));
        self::assertStringContainsString('->lockForUpdate()', $contractWorkflow);
        self::assertStringContainsString('public readonly bool $partialUpdate = false', $this->dtoSource());
        self::assertStringContainsString('array_intersect_key($data, $provided)', $this->dtoSource());

        $controller = file_get_contents(
            dirname(__DIR__, 3)
            .'/app/Http/Controllers/Api/V1/Admin/Contract/ContractPerformanceActController.php',
        );
        self::assertIsString($controller);
        self::assertStringContainsString("attributes->get('current_organization_id')", $controller);
        self::assertStringNotContainsString('user()?->organization_id', $controller);

        $race = file_get_contents(
            dirname(__DIR__, 3)
            .'/tests/Feature/Reporting/Waves23/AcceptedProductionWorkflowConcurrencyPostgresTest.php',
        );
        self::assertIsString($race);
        self::assertStringContainsString("'CREATE SCHEMA '.\$this->schema", $race);
        self::assertStringContainsString("'DROP SCHEMA '.\$this->schema.' CASCADE'", $race);
        self::assertStringContainsString("'.search_path' => \$this->schema", $race);
        self::assertStringNotContainsString('Organization::factory()', $race);
    }

    #[Test]
    public function unprojectable_runtime_transitions_are_recorded_as_explicit_coverage_gaps(): void
    {
        $recorder = file_get_contents(
            dirname(__DIR__, 3)
            .'/app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'ProductionAcceptanceEventRecorder.php',
        );
        $gapRecorder = file_get_contents(
            dirname(__DIR__, 3)
            .'/app/Services/CompletedWork/Reporting/AcceptedProduction/Services/'
            .'ProductionAcceptanceCoverageGapRecorder.php',
        );

        self::assertIsString($recorder);
        self::assertIsString($gapRecorder);
        self::assertStringContainsString('production_acceptance_reversal_without_acceptance', $recorder);
        self::assertStringContainsString('legacy_history_unavailable', $recorder);
        self::assertStringContainsString("'production_acceptance_scope_mismatch' => 'scope_unavailable'", $recorder);
        self::assertStringContainsString('source_identity_unavailable', $recorder);
        self::assertStringContainsString("'status' => 'unprovable'", $gapRecorder);
        self::assertStringContainsString('ProductionAcceptanceBackfillLedger::query()->firstOrCreate', $gapRecorder);
    }

    private function dtoSource(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/DTOs/Contract/ContractPerformanceActDTO.php',
        );
        self::assertIsString($source);

        return $source;
    }
}
