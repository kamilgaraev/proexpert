<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;

final class ReportCleanupEvidenceReentryTest extends TestCase
{
    public function test_cleanup_fixture_contains_the_closed_check_sequence(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/Reporting/Quality/report-cleanup-evidence.valid.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('cleanup_verified', $fixture['status']);
        self::assertSame(['cleanup.cutover_pair', 'cleanup.rollback_window', 'cleanup.legacy_route_aliases', 'cleanup.legacy_direct_callers', 'cleanup.qg14_forbidden_symbols', 'cleanup.policy_lock'], array_column($fixture['checks'], 'check_id'));
    }
}

