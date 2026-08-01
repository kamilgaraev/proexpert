<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;

final class ReportAdminEvidenceTransferReentryTest extends TestCase
{
    public function test_admin_transfer_fixture_keeps_its_distinct_identity(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/Reporting/Quality/plan-4-admin-evidence-transfer.valid.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('plan4_admin_evidence_transfer', $fixture['artifact_id']);
        self::assertSame('admin-evidence', $fixture['kind']);
    }
}

