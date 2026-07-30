<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use PHPUnit\Framework\TestCase;

final class ReportSnapshotSealBackfillContractTest extends TestCase
{
    public function test_ready_requires_per_snapshot_identity_and_trust_validation(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportSnapshotSealBackfill.php',
        );

        self::assertStringContainsString('validateCoverage(', $source);
        self::assertStringContainsString('report_snapshot_seal_backfill_orphan', $source);
        self::assertStringContainsString('report_snapshot_seal_backfill_timestamp_mismatch', $source);
        self::assertStringContainsString('report_snapshot_seal_backfill_payload_mismatch', $source);
        self::assertStringContainsString('$this->verifier->assertTrusted(', $source);
        self::assertStringContainsString(
            'verify_snapshot_identity_then_reseal_with_trusted_active_key',
            $source,
        );
        self::assertStringNotContainsString("&& \$sourceCount === \$sealedCount) {\n                    return;", $source);
    }

    public function test_source_hash_builder_separates_authoritative_source_from_snapshot_identity(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Execution/CanonicalReportSourceHashBuilder.php',
        );

        self::assertStringContainsString('report_source_hash_identity_mismatch', $source);
        self::assertStringContainsString('return $snapshot->sourceHash;', $source);
        self::assertStringContainsString('public function snapshotIdentity(', $source);
    }
}
