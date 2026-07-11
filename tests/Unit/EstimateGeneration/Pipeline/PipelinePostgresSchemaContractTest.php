<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use PHPUnit\Framework\TestCase;

final class PipelinePostgresSchemaContractTest extends TestCase
{
    public function test_checkpoint_and_outbox_migrations_enforce_production_invariants(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/';
        $checkpoint = file_get_contents($root.'2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php');
        $outbox = file_get_contents($root.'2026_07_11_000600_create_estimate_generation_finalization_outbox_table.php');

        self::assertIsString($checkpoint);
        self::assertStringContainsString('eg_checkpoint_immutable_guard', $checkpoint);
        self::assertStringContainsString('eg_checkpoint_delete_guard', $checkpoint);
        self::assertStringContainsString('eg_checkpoint_aggregate_guard', $checkpoint);
        self::assertStringContainsString('FOR UPDATE', $checkpoint);
        self::assertStringContainsString('8388608', $checkpoint);
        self::assertStringContainsString('dependency_versions', $checkpoint);
        self::assertIsString($outbox);
        self::assertStringContainsString('eg_finalization_state_ck', $outbox);
        self::assertStringContainsString('idempotency_key', $outbox);
        self::assertStringContainsString('lease_expires_at', $outbox);
        self::assertStringContainsString('estimate_generation_recovery_cursors', $outbox);
        self::assertStringContainsString('estimate_generation_finalization_deliveries', $outbox);
        self::assertStringNotContainsString('eg_notification_idempotency_uq', $outbox);
        self::assertStringNotContainsString('ON notifications', $outbox);
        self::assertStringNotContainsString('data::jsonb', $outbox);
        self::assertStringContainsString("TG_OP = 'DELETE'", $outbox);
        self::assertStringContainsString('pg_trigger_depth() <= 1', $outbox);
        self::assertStringContainsString("OLD.status = 'pending' AND NEW.status = 'delivered'", $outbox);
        self::assertStringContainsString("to_jsonb(OLD) - ARRAY['status','notification_id','delivered_at','updated_at']", $outbox);
        self::assertStringContainsString('finalization_delivery_is_immutable', $outbox);
    }
}
