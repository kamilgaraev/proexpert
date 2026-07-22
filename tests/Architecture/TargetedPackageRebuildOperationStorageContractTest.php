<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetedPackageRebuildOperationStorageContractTest extends TestCase
{
    #[Test]
    public function it_declares_tenant_bound_idempotent_and_recoverable_operation_storage(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_22_000200_create_estimate_generation_targeted_rebuild_operations.php',
        );

        self::assertStringContainsString("Schema::create('estimate_generation_targeted_rebuild_operations'", $migration);
        self::assertStringContainsString("unique(['session_id', 'expected_state_version', 'source_input_version', 'root_input_hash', 'package_key']", $migration);
        self::assertStringContainsString("unique('idempotency_key', 'eg_targeted_rebuild_idempotency_key_uq')", $migration);
        self::assertStringContainsString("foreign(['session_id', 'organization_id', 'project_id']", $migration);
        self::assertStringContainsString("references(['id', 'organization_id', 'project_id'])", $migration);
        self::assertStringContainsString('eg_targeted_rebuild_status_ck', $migration);
        self::assertStringContainsString('eg_targeted_rebuild_identifier_ck', $migration);
        self::assertStringContainsString('eg_targeted_rebuild_lease_ck', $migration);
        self::assertStringContainsString('eg_targeted_rebuild_lifecycle_ck', $migration);
        self::assertStringContainsString("status = 'running' AND attempt_count >= 1 AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL AND result_delta = '{}'::jsonb AND safe_arbiter_review = '{}'::jsonb", $migration);
        self::assertStringContainsString("jsonb_typeof(result_delta->'target_package') = 'object'", $migration);
        self::assertStringContainsString("jsonb_typeof(safe_arbiter_review->'findings') = 'array'", $migration);
        self::assertStringContainsString("safe_arbiter_review->>'status' = 'unavailable' AND safe_arbiter_review->>'outcome' = 'human_review'", $migration);
        self::assertStringContainsString("status = 'reviewed' AND attempt_count >= 1 AND lease_token IS NULL AND lease_expires_at IS NULL AND result_delta <> '{}'::jsonb AND safe_arbiter_review->>'status' = 'reviewed' AND safe_arbiter_review->>'outcome' IN ('passed','confirmed_scope_only')", $migration);
        self::assertStringContainsString("status = 'committed' AND attempt_count >= 1 AND lease_token IS NULL AND lease_expires_at IS NULL AND result_delta <> '{}'::jsonb AND safe_arbiter_review->>'status' = 'reviewed' AND safe_arbiter_review->>'outcome' IN ('passed','confirmed_scope_only')", $migration);
        self::assertStringContainsString("status = 'human_review' AND attempt_count >= 1 AND lease_token IS NULL AND lease_expires_at IS NULL AND safe_arbiter_review->>'status' IN ('reviewed','unavailable') AND safe_arbiter_review->>'outcome' = 'human_review'", $migration);
        self::assertStringContainsString("jsonb_path_exists(result_delta, '$.**.draft_payload')", $migration);
        self::assertStringContainsString("jsonb_path_exists(safe_arbiter_review, '$.**.prompt_payload')", $migration);
        self::assertStringContainsString('eg_targeted_rebuild_claim_recovery_idx', $migration);
        self::assertStringNotContainsString("jsonb('draft_payload')", $migration);
        self::assertStringNotContainsString("jsonb('prompt_payload')", strtolower($migration));
        self::assertStringNotContainsString("jsonb('document_payload')", strtolower($migration));
    }

    #[Test]
    public function it_executes_postgres_json_key_constraints_without_question_mark_operators(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_22_000200_create_estimate_generation_targeted_rebuild_operations.php',
        );

        self::assertMatchesRegularExpression(
            "/DB::statement\\(<<<'SQL'\\R\\s*ALTER TABLE public\\.estimate_generation_targeted_rebuild_operations\\R\\s*ADD CONSTRAINT eg_targeted_rebuild_result_ck CHECK \\(/",
            $migration,
        );
        self::assertStringContainsString("jsonb_path_exists(result_delta, '$.target_package')", $migration);
        self::assertStringContainsString("jsonb_path_exists(safe_arbiter_review, '$.findings')", $migration);
        self::assertStringNotContainsString(' ?& ', $migration);
        self::assertStringNotContainsString(" ? 'key'", $migration);
        self::assertStringNotContainsString(" ? 'sections'", $migration);
    }
}
