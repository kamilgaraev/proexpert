<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScaledPieceUnitConversionMigrationTest extends TestCase
{
    #[Test]
    public function ten_piece_norm_resources_use_a_versioned_price_unit_conversion(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_19_000100_register_scaled_piece_unit_conversion.php');

        self::assertIsString($migration);
        self::assertStringContainsString("'from_unit' => '10 шт'", $migration);
        self::assertStringContainsString("'to_unit' => 'шт'", $migration);
        self::assertStringContainsString("'factor' => '10.000000000000'", $migration);
        self::assertStringContainsString("'version' => 1", $migration);
        self::assertStringContainsString("hash('sha256'", $migration);
        self::assertStringContainsString('insertOrIgnore', $migration);
        self::assertStringContainsString('estimate_generation.scaled_piece_conversion_conflict', $migration);
        self::assertStringContainsString("->first(['factor', 'fingerprint', 'is_active'])", $migration);
        self::assertStringNotContainsString('->delete()', $migration);
    }
}
