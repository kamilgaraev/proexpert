<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityMigrationContractTest extends TestCase
{
    #[Test]
    public function postgres_guard_pins_the_complete_v1_policy_and_unavailability_bound(): void
    {
        $migration = file_get_contents(
            __DIR__.'/../../../../../app/BusinessModules/Features/WorkforceManagement/migrations/'
            .'2026_08_01_000030_create_workforce_capacity_source.php',
        );

        self::assertIsString($migration);
        foreach ([
            'expected_policy := workforce_capacity_expected_policy(organization_timezone)',
            "'effective_date_semantics', 'inclusive'",
            "'staff_unit_rule', 'active_effective_not_deleted'",
            "'weekly_pattern_keys'",
            "'weekly_pattern_shapes'",
            "'weekly_pattern_hours_rule', 'explicit_only_no_default'",
            "'rounding', jsonb_build_object(",
            "'formula_order'",
            "'status_precedence'",
            "'source_item_order'",
            "'capture_kinds'",
            "'gap_codes'",
            "'redacted_fields'",
            'NEW.policy_definition IS DISTINCT FROM expected_policy',
            'NEW.approved_unavailability_fte > NEW.assigned_fte',
        ] as $requiredFragment) {
            self::assertStringContainsString($requiredFragment, $migration);
        }
        self::assertSame(1, substr_count($migration, 'CREATE FUNCTION workforce_capacity_expected_policy'));
    }

    #[Test]
    public function deferred_capture_uses_immutable_child_source_and_a_closed_state_machine(): void
    {
        $migration = file_get_contents(
            __DIR__.'/../../../../../app/BusinessModules/Features/WorkforceManagement/migrations/'
            .'2026_08_01_000030_create_workforce_capacity_source.php',
        );

        self::assertIsString($migration);
        foreach ([
            "Schema::create('workforce_capacity_capture_ranges'",
            "Schema::create('workforce_capacity_frozen_source_rows'",
            "'capture_request_id'",
            'workforce_capacity_capture_range_insert_guard',
            'workforce_capacity_frozen_source_insert_guard',
            'unit.organization_id = NEW.organization_id',
            'project.organization_id = NEW.organization_id',
            "OLD.status = 'preparing'",
            "NEW.status = 'completed'",
            'NEW.range_count = 0',
            'NEW.source_row_count = 0',
            'NEW.frozen_at < NEW.captured_at',
            'NEW.available_at >= NEW.frozen_at',
            "OLD.status = 'pending'",
            'NEW.attempt_count = OLD.attempt_count + 1',
            'NEW.chunk_count = OLD.chunk_count + 1',
            "NEW.status = 'dead_lettered'",
            'request.frozen_at IS NOT NULL',
            'request.policy_definition IS DISTINCT FROM NEW.policy_definition',
            'request.policy_canonical IS DISTINCT FROM NEW.policy_canonical',
            'workforce_capacity_frozen_source_rows AS frozen',
            "frozen.payload IS NOT DISTINCT FROM NEW.source_canonical::jsonb->'source'",
            'workforce_capacity_snapshot_request_idem_unique',
            ') WHERE capture_request_id IS NOT NULL',
            'workforce_capacity_snapshot_live_idem_unique',
            ') WHERE capture_request_id IS NULL',
            'NEW.attempt_count = 0',
            'frozen.valid_from <= snapshot.as_of_date',
            'frozen.valid_to >= snapshot.as_of_date',
            "Schema::create('workforce_capacity_snapshots'",
            "\$table->unsignedBigInteger('staff_unit_id')",
            "\$table->unsignedBigInteger('sealed_employee_id')->nullable()",
            "NEW.lineage->>'organization_id'",
            "NEW.lineage->>'staff_unit_id'",
            "NEW.lineage->>'project_id'",
            "NEW.lineage->>'month_start'",
        ] as $requiredFragment) {
            self::assertStringContainsString($requiredFragment, $migration);
        }

        self::assertStringNotContainsString('frozen_generation', $migration);
    }

    #[Test]
    public function persistence_keeps_snapshot_idempotency_inside_the_exact_request(): void
    {
        $snapshotStore = file_get_contents(
            __DIR__.'/../../../../../app/BusinessModules/Features/WorkforceManagement/Reporting/Capacity/Services/'
            .'EloquentWorkforceCapacitySnapshotStore.php',
        );
        $deferredStore = file_get_contents(
            __DIR__.'/../../../../../app/BusinessModules/Features/WorkforceManagement/Reporting/Capacity/Services/'
            .'EloquentWorkforceCapacityDeferredCaptureStore.php',
        );

        self::assertIsString($snapshotStore);
        self::assertIsString($deferredStore);
        self::assertStringContainsString("->where('capture_request_id', \$captureRequestId)", $snapshotStore);
        self::assertStringContainsString("'attempt_count' => 0", $deferredStore);
    }
}
