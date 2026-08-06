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
            .'projects, contracts, contract_project_allocations, '
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
        foreach ([
            'request_source_incomplete',
            'version_scope_drift',
            'workflow_event_scope_drift',
            'claim_link_scope_drift',
            'ledger_scope_drift',
        ] as $gapKind) {
            self::assertStringContainsString($gapKind, $migration);
        }
        self::assertStringContainsString('most_seed_change_claim_history_checkpoint_v1', $migration);
        self::assertStringContainsString('most_change_claim_canonical_json_v1', $migration);
        self::assertStringContainsString('most_change_claim_canonical_hash_v1', $migration);
        self::assertLessThan(
            strpos($migration, 'WITH boundary AS MATERIALIZED'),
            strpos($migration, 'CREATE OR REPLACE FUNCTION most_change_claim_canonical_hash_v1'),
        );
        self::assertStringContainsString('most_change_claim_source_insert_guard_v1', $migration);
        foreach ([
            'change_request_versions_scope_hash_guard',
            'change_workflow_events_scope_hash_guard',
            'change_claim_links_scope_hash_guard',
            'contingency_ledger_entries_scope_hash_guard',
        ] as $trigger) {
            self::assertStringContainsString($trigger, $migration);
        }
        self::assertSame(4, substr_count($migration, 'BEFORE INSERT ON'));
        foreach ([
            'request_project.organization_id IS DISTINCT FROM request.organization_id',
            'request_allocation.project_id IS DISTINCT FROM request.project_id',
            'request_contract.organization_id IS DISTINCT FROM request.organization_id',
            'version_project.organization_id IS DISTINCT FROM version.organization_id',
            'claim.project_id IS DISTINCT FROM version.project_id',
            'ledger_project.organization_id IS DISTINCT FROM ledger.organization_id',
        ] as $scopeGuard) {
            self::assertStringContainsString($scopeGuard, $migration);
        }
        foreach ([
            'version.source_hash IS DISTINCT FROM most_change_claim_canonical_hash_v1',
            'event.event_hash IS DISTINCT FROM most_change_claim_canonical_hash_v1',
            'link.source_hash IS DISTINCT FROM most_change_claim_canonical_hash_v1',
            'ledger.entry_hash IS DISTINCT FROM most_change_claim_canonical_hash_v1',
        ] as $legacyHashGuard) {
            self::assertStringContainsString($legacyHashGuard, $migration);
        }
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

    #[Test]
    public function source_writers_normalize_instants_to_utc_before_hashing(): void
    {
        $root = dirname(__DIR__, 3)
            .'/app/BusinessModules/Features/ChangeManagement/Reporting/ChangeClaim/Services/';

        foreach ([
            'ChangeWorkflowEventRecorder.php',
            'ContingencyLedgerService.php',
        ] as $service) {
            $source = file_get_contents($root.$service);

            self::assertIsString($source);
            self::assertStringContainsString('ChangeClaimSourceInstant::from(', $source, $service);
        }

        $recorder = file_get_contents($root.'ChangeWorkflowEventRecorder.php');
        self::assertIsString($recorder);
        self::assertStringContainsString(
            '$this->recordContingency($change, $versionRecord, $eventType, $occurredAt);',
            $recorder,
        );
    }
}
