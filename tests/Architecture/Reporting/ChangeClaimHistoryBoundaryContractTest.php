<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChangeClaimHistoryBoundaryContractTest extends TestCase
{
    #[Test]
    public function migration_freezes_real_source_coverage_without_reconstructing_legacy_events(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 3)
            .'/database/migrations/2026_08_06_000210_create_change_claim_history_checkpoints.php',
        );

        self::assertIsString($migration);
        self::assertStringContainsString("Schema::create('change_claim_history_checkpoints'", $migration);
        self::assertStringContainsString(
            'LOCK TABLE organizations, change_management_change_requests, '
            .'change_management_impacts, change_management_approvals, change_management_claims, '
            .'change_request_versions, change_workflow_events, change_claim_links, '
            .'contingency_ledger_entries',
            $migration,
        );
        self::assertStringContainsString('clock_timestamp()::timestamptz(6)', $migration);
        foreach ([
            'change_request_count',
            'change_request_watermark_id',
            'change_request_set_hash',
            'version_count',
            'version_watermark_id',
            'version_set_hash',
            'workflow_event_count',
            'workflow_event_watermark_id',
            'workflow_event_set_hash',
            'claim_link_count',
            'claim_link_watermark_id',
            'claim_link_set_hash',
            'ledger_count',
            'ledger_watermark_id',
            'ledger_set_hash',
            'unprojectable_legacy_count',
            'unprojectable_legacy_set_hash',
        ] as $field) {
            self::assertStringContainsString($field, $migration);
        }
        self::assertStringContainsString('change_claim_history_checkpoints_append_only', $migration);
        self::assertStringContainsString('most_seed_change_claim_history_checkpoint_v1', $migration);
        self::assertStringContainsString('report_change_claim_history_boundary_completed', $migration);
        self::assertStringContainsString('$evidence = DB::transaction', $migration);
        self::assertStringContainsString('DB::afterCommit', $migration);
        self::assertGreaterThan(
            strpos($migration, 'DB::afterCommit'),
            strpos($migration, "Log::info('report_change_claim_history_boundary_completed'"),
        );
        self::assertStringContainsString('checkpoint organization coverage mismatch', $migration);
        self::assertStringNotContainsString('INSERT INTO change_request_versions', $migration);
        self::assertStringNotContainsString('INSERT INTO change_workflow_events', $migration);
        self::assertStringNotContainsString('ChangeClaimBackfill', $migration);
        self::assertStringNotContainsString('ChangeWorkflowEventRecorder', $migration);
    }

    #[Test]
    public function checkpoint_model_is_append_only_by_convention(): void
    {
        $model = file_get_contents(
            dirname(__DIR__, 3)
            .'/app/BusinessModules/Features/ChangeManagement/Reporting/ChangeClaim/Models/'
            .'ChangeClaimHistoryCheckpoint.php',
        );

        self::assertIsString($model);
        self::assertStringContainsString(
            "protected \$table = 'change_claim_history_checkpoints';",
            $model,
        );
        self::assertStringContainsString('public $timestamps = false;', $model);
        self::assertStringContainsString("'completed_at' => 'immutable_datetime'", $model);
        self::assertStringContainsString('change_claim_history_checkpoint_immutable', $model);
    }
}
