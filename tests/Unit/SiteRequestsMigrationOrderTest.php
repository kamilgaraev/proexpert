<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SiteRequestsMigrationOrderTest extends TestCase
{
    public function test_site_requests_table_runs_after_its_foreign_key_targets(): void
    {
        $migration = '2025_01_01_000031_create_site_requests_table.php';

        self::assertFileExists(
            dirname(__DIR__, 2).'/app/BusinessModules/Features/SiteRequests/migrations/'.$migration,
        );

        foreach ([
            '2025_01_01_000010_create_organizations_table.php',
            '2025_01_01_000020_create_projects_table.php',
        ] as $prerequisite) {
            self::assertLessThan($migration, $prerequisite);
        }
    }
}
