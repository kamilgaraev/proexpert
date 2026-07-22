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
        self::assertStringContainsString("jsonb_typeof(result_delta->'target_package') = 'object'", $migration);
        self::assertStringContainsString("jsonb_typeof(safe_arbiter_review->'findings') = 'array'", $migration);
        self::assertStringContainsString("jsonb_path_exists(result_delta, '$.**.draft_payload')", $migration);
        self::assertStringContainsString("jsonb_path_exists(safe_arbiter_review, '$.**.prompt_payload')", $migration);
        self::assertStringContainsString('eg_targeted_rebuild_claim_recovery_idx', $migration);
        self::assertStringNotContainsString("jsonb('draft_payload')", $migration);
        self::assertStringNotContainsString("jsonb('prompt_payload')", strtolower($migration));
        self::assertStringNotContainsString("jsonb('document_payload')", strtolower($migration));
    }
}
