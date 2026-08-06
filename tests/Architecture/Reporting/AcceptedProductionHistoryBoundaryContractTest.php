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
}
