<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\SourceSnapshots;

use PHPUnit\Framework\TestCase;

final class ReportSourceSnapshotMigrationContractTest extends TestCase
{
    public function test_persistence_schema_declares_snapshot_bound_rows_and_ready_immutability(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/database/migrations/2026_07_31_000001_create_report_source_snapshots_tables.php');

        self::assertIsString($migration);
        self::assertStringContainsString("Schema::create('report_source_snapshots'", $migration);
        self::assertStringContainsString("Schema::create('report_source_snapshot_rows'", $migration);
        self::assertStringContainsString("Schema::create('report_source_snapshot_drill_rows'", $migration);
        self::assertStringContainsString('report_source_snapshot_prevent_ready_mutation', $migration);
        self::assertStringContainsString('report_source_snapshot_rows_page_idx', $migration);
        self::assertStringContainsString('report_source_snapshot_drill_rows_page_idx', $migration);
    }
}
