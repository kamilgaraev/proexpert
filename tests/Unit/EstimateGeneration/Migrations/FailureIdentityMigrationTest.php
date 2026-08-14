<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Migrations;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FailureIdentityMigrationTest extends TestCase
{
    #[Test]
    public function scoped_identity_migration_does_not_rewrite_unrelated_failure_event_constraints(): void
    {
        $path = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_001100_scope_failure_identities.php';
        $source = file_get_contents($path);

        self::assertIsString($source);
        self::assertStringContainsString('estimate_generation_failure_identities', $source);
        self::assertStringNotContainsString('estimate_generation_failure_events', $source);
        self::assertStringNotContainsString('safe_context', $source);
    }
}
