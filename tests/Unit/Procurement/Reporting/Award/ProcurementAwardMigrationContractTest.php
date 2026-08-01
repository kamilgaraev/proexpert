<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Award;

use PHPUnit\Framework\TestCase;

final class ProcurementAwardMigrationContractTest extends TestCase
{
    public function test_migration_defines_append_only_award_evidence_and_deferred_manifest_guards(): void
    {
        $migration = $this->migration();

        foreach ([
            "Schema::create('procurement_award_policy_versions'",
            "Schema::create('procurement_award_evidence_events'",
            "Schema::create('procurement_award_evidence_candidates'",
            "['decision_id', 'decision_revision', 'event_type']",
            "['decision_id', 'event_sequence']",
            "['event_id', 'ordinal']",
            'CREATE CONSTRAINT TRIGGER proc_award_events_deferred_validate',
            'CREATE CONSTRAINT TRIGGER proc_award_candidates_deferred_validate',
            'CREATE TRIGGER proc_award_candidate_source_validate',
            'procurement award candidate selection state mismatch',
            'DEFERRABLE INITIALLY DEFERRED',
            "['procurement_award_policy_versions', 'proc_award_policies_append_only']",
            "['procurement_award_evidence_events', 'proc_award_events_append_only']",
            "['procurement_award_evidence_candidates', 'proc_award_candidates_append_only']",
            'BEFORE UPDATE OR DELETE ON %s',
            'procurement_award_deferred_validate()',
            "RAISE EXCEPTION 'procurement award predecessor required'",
            "event_type = 'selection_superseded'",
            'prior_revision_event.event_sequence',
            'decision.winning_supplier_proposal_version_id IS NOT DISTINCT FROM checked_event.selected_proposal_version_id',
            'proposal.status IS NOT DISTINCT FROM NEW.proposal_status',
            "event.event_type = 'comparison_captured'",
            'procurement award outcome candidate predecessor mismatch',
            'procurement award predecessor evidence mismatch',
            'predecessor.selection_fingerprint <> checked_event.selection_fingerprint',
            'predecessor.reason_digest IS DISTINCT FROM checked_event.reason_digest',
            "['event_id', 'proposal_id']",
        ] as $contract) {
            self::assertStringContainsString($contract, $migration);
        }
    }

    public function test_migration_marks_existing_versions_unverified_without_historical_evidence_backfill(): void
    {
        $migration = $this->migration();

        self::assertStringContainsString("->default('legacy_unverified')", $migration);
        self::assertStringNotContainsString("DB::table('supplier_proposal_versions')", $migration);
        self::assertStringNotContainsString("DB::table('supplier_request_versions')", $migration);
        self::assertStringNotContainsString('chunkById(', $migration);
        self::assertStringNotContainsString('cursor()', $migration);
    }

    private function migration(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 5).'/app/BusinessModules/Features/Procurement/migrations/2026_08_01_000002_create_procurement_award_source.php',
        );

        self::assertIsString($source);

        return $source;
    }
}
