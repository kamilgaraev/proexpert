<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormativeCatalogBackfillMigrationTest extends TestCase
{
    #[Test]
    public function migration_backfills_search_vectors_and_only_unambiguous_implicit_units(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000500_backfill_norm_search_and_implicit_units.php');

        self::assertIsString($source);
        self::assertStringContainsString('WHERE search_vector IS NULL', $source);
        self::assertStringContainsString("to_tsvector('russian'", $source);
        self::assertStringContainsString("WHEN resource_type IN ('labor','machine_labor') THEN 'чел.-ч'", $source);
        self::assertStringContainsString("WHEN resource_type IN ('machine','machinery') THEN 'маш.-ч'", $source);
        self::assertStringContainsString("WHERE NULLIF(BTRIM(unit), '') IS NULL", $source);
        self::assertStringNotContainsString("resource_type IN ('material','equipment')", $source);
    }
}
