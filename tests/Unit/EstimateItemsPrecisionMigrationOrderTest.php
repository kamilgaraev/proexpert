<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EstimateItemsPrecisionMigrationOrderTest extends TestCase
{
    public function test_precision_migration_runs_after_estimate_item_columns_exist(): void
    {
        $directory = dirname(__DIR__, 2).'/database/migrations/';
        $migration = $directory.'2025_11_01_000011_increase_quantity_precision_in_estimate_items.php';

        self::assertFileExists($migration);
        self::assertFileDoesNotExist(
            $directory.'2024_02_22_000000_increase_quantity_precision_in_estimate_items.php',
        );
        foreach ([
            '2025_10_21_120200_create_estimate_items_table.php',
            '2025_10_21_184941_add_extended_price_columns_to_estimate_items_table.php',
            '2025_11_01_000001_create_normative_bases_tables.php',
            '2025_11_01_000003_extend_estimate_items_table.php',
            '2025_11_01_000010_add_estimate_performance_indices.php',
        ] as $prerequisite) {
            self::assertLessThan(basename($migration), $prerequisite);
        }
        self::assertStringContainsString("Schema::table('estimate_items'", (string) file_get_contents($migration));
        self::assertStringContainsString("current_unit_price", (string) file_get_contents($migration));
    }
}
