<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use PHPUnit\Framework\TestCase;

final class ProcurementCycleMigrationContractTest extends TestCase
{
    public function test_trigger_pins_terminal_reason_to_the_exact_policy_version(): void
    {
        $migration = $this->migration();

        self::assertStringContainsString(
            "IF NEW.event_code = 'cancelled'\n       AND NEW.policy_version_id IS NULL THEN",
            $migration,
        );
        self::assertStringNotContainsString(
            "NEW.terminal_reason IS DISTINCT FROM 'request_rejected'",
            $migration,
        );
        self::assertStringContainsString(
            'pinned_policy.terminal_cancellation_policy @> jsonb_build_array(NEW.terminal_reason)',
            $migration,
        );
    }

    public function test_checks_reject_null_gates_for_partial_pins_and_quality_gaps(): void
    {
        $migration = $this->migration();

        foreach ([
            'policy_hash IS NOT NULL',
            'calendar_version IS NOT NULL',
            'calendar_hash IS NOT NULL',
            'procurement_process_event_dimension_quality_check',
            "jsonb_typeof(dimension_snapshot->'gap_codes') IS NOT DISTINCT FROM 'array'",
            "dimension_snapshot->>'quality_status' = 'PARTIAL'",
            "jsonb_array_length(dimension_snapshot->'gap_codes') > 0",
            "dimension_snapshot->'gap_codes' @> '[\"missing_request_created_event\", \"missing_project_lineage\", \"missing_policy_version\"]'::jsonb) IS NOT TRUE",
        ] as $invariant) {
            self::assertStringContainsString($invariant, $migration);
        }
    }

    public function test_trigger_uses_request_created_or_quarantine_for_late_project_provenance(): void
    {
        $migration = $this->migration();

        self::assertStringContainsString("IF NEW.event_code = 'request_created' THEN", $migration);
        self::assertStringContainsString('procurement_process_event_request_created_unique', $migration);
        self::assertStringContainsString('created_event.project_id IS DISTINCT FROM NEW.project_id', $migration);
        self::assertStringContainsString('procurement process event quarantine provenance required', $migration);
        self::assertStringContainsString('missing_request_created_event', $migration);
    }

    public function test_trigger_validates_one_full_optional_lineage_chain(): void
    {
        $migration = $this->migration();

        foreach ([
            'supplier_requests.supplier_party_id = NEW.supplier_party_id',
            'supplier_proposals.supplier_party_id = NEW.supplier_party_id',
            'supplier_proposal_versions.supplier_proposal_id = NEW.supplier_proposal_id',
            'winning_supplier_proposal_version_id = NEW.supplier_proposal_version_id',
            'accepted_supplier_proposal_id IS NOT DISTINCT FROM NEW.supplier_proposal_id',
            'accepted_supplier_proposal_version_id IS NOT DISTINCT FROM NEW.supplier_proposal_version_id',
            'procurement_process_event_supplier_proposal_pair_check',
            '(NEW.supplier_proposal_id IS NULL) <> (NEW.supplier_proposal_version_id IS NULL)',
            'supplier_proposal_lines.supplier_proposal_id = NEW.supplier_proposal_id',
            'supplier_proposal_lines.supplier_request_line_id = NEW.supplier_request_line_id',
            'AND organization_id = NEW.organization_id',
        ] as $invariant) {
            self::assertStringContainsString($invariant, $migration);
        }
    }

    public function test_ci_fails_when_postgresql_contract_suite_is_skipped(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 5).'/.github/workflows/notification-concurrency.yml');
        self::assertIsString($workflow);

        $command = 'php artisan test tests/Feature/Procurement/Reporting/Cycle/'
            .'ProcurementCycleSourcePostgresTest.php';
        self::assertStringContainsString($command, $workflow);
        self::assertStringContainsString('--group=postgresql --fail-on-skipped', $workflow);
    }

    private function migration(): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 5)
            .'/app/BusinessModules/Features/Procurement/migrations/2026_08_01_000001_create_procurement_cycle_source.php',
        );
        self::assertIsString($contents);

        return $contents;
    }
}
