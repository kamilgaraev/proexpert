<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class ContractorScorecardPostgresTest extends TestCase
{
    public function test_schema_is_append_only_and_materialization_locks_the_tenant_aggregate(): void
    {
        $root = dirname(__DIR__, 4);
        $migration = file_get_contents($root.'/database/migrations/2026_07_26_090000_create_contractor_scorecard_reporting_tables.php');
        $materializer = file_get_contents($root.'/app/BusinessModules/ContractorMarketplace/Reporting/Scorecard/Services/ContractorScorecardSnapshotMaterializer.php');

        self::assertIsString($migration);
        self::assertIsString($materializer);
        self::assertStringContainsString('contractor_scorecard_snapshots', $migration);
        self::assertStringContainsString('_append_only BEFORE UPDATE OR DELETE', $migration);
        self::assertStringContainsString("DB::table('organizations')", $materializer);
        self::assertStringContainsString('lockForUpdate()', $materializer);
    }
}
