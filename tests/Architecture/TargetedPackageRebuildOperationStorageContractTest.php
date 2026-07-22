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
        self::assertStringContainsString("foreign(['session_id', 'organization_id', 'project_id']", $migration);
        self::assertStringContainsString("references(['id', 'organization_id', 'project_id'])", $migration);
        self::assertStringContainsString('eg_targeted_rebuild_status_ck', $migration);
        self::assertStringContainsString('eg_targeted_rebuild_identifier_ck', $migration);
        self::assertStringContainsString('eg_targeted_rebuild_lease_ck', $migration);
        self::assertStringContainsString('eg_targeted_rebuild_claim_recovery_idx', $migration);
        self::assertStringNotContainsString('draft_payload', $migration);
        self::assertStringNotContainsString('prompt_payload', strtolower($migration));
        self::assertStringNotContainsString('document_payload', strtolower($migration));
    }
}
