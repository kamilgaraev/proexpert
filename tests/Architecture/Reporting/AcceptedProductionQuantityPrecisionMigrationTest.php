<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedProductionQuantityPrecisionMigrationTest extends TestCase
{
    #[Test]
    public function event_quantities_preserve_the_four_decimal_source_contract(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 3)
            .'/database/migrations/2026_08_06_000250_widen_production_acceptance_quantity_precision.php',
        );

        self::assertIsString($migration);
        self::assertSame(3, substr_count($migration, 'TYPE NUMERIC(20, 4)'));
        self::assertStringContainsString('accepted_quantity_delta', $migration);
        self::assertStringContainsString('planned_quantity', $migration);
        self::assertStringContainsString('reported_quantity', $migration);
        self::assertStringContainsString(
            'production_acceptance_quantity_precision_rollback_unsafe',
            $migration,
        );
    }
}
