<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use PHPUnit\Framework\TestCase;

final class WarehouseIdempotencyMigrationContractTest extends TestCase
{
    public function test_partial_indexes_do_not_use_the_pdo_question_mark_placeholder(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BasicWarehouse/migrations/2026_08_23_000003_add_manual_warehouse_operation_idempotency_guards.php'
        );

        self::assertStringNotContainsString("metadata ? 'idempotency_key'", $migration);
        self::assertSame(2, substr_count($migration, "metadata->>'idempotency_key' IS NOT NULL"));
    }
}
