<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use PHPUnit\Framework\TestCase;

final class RegionalPriceVersionInsertGuardMigrationTest extends TestCase
{
    public function test_insert_does_not_access_old_regional_price_version_row(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_21_000100_fix_regional_price_version_insert_guard.php');

        self::assertIsString($migration);
        self::assertStringContainsString("IF TG_OP='DELETE' THEN", $migration);
        self::assertStringContainsString("ELSIF TG_OP='UPDATE' AND OLD.status", $migration);
        self::assertStringNotContainsString("TG_OP='DELETE'\n    AND OLD.status", $migration);
    }
}
