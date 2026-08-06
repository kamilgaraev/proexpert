<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class LegacyStorageMaintenanceArchitectureTest extends TestCase
{
    public function test_legacy_storage_scanners_and_automatic_report_cleanup_are_removed(): void
    {
        foreach ([
            'app/Console/Commands/CleanupFilesCommand.php',
            'app/Console/Commands/CleanupReportFilesCommand.php',
            'app/Console/Commands/SyncReportFilesCommand.php',
            'app/Console/Commands/SyncOrgBucketUsageCommand.php',
        ] as $relativePath) {
            self::assertFileDoesNotExist(__DIR__.'/../../../'.$relativePath);
        }

        $consoleRoutes = file_get_contents(__DIR__.'/../../../routes/console.php');
        self::assertIsString($consoleRoutes);
        self::assertStringNotContainsString("Schedule::command('reports:cleanup')", $consoleRoutes);
        self::assertStringNotContainsString("Schedule::command('reports:sync')", $consoleRoutes);
        self::assertStringNotContainsString("Schedule::command('org:sync-bucket-usage')", $consoleRoutes);
    }
}
