<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Migrations;

use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestContextForwardOnlyMigrationTest extends TestCase
{
    #[Test]
    public function rollback_is_a_non_destructive_noop_for_immutable_request_context(): void
    {
        $migration = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000100_add_request_context_to_estimate_generation_ai_usage.php';

        self::assertInstanceOf(Migration::class, $migration);
        $migration->down();
        self::addToAssertionCount(1);
    }

    #[Test]
    public function physical_attempt_journal_is_also_forward_only(): void
    {
        $migration = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000300_create_vision_physical_attempts.php';

        self::assertInstanceOf(Migration::class, $migration);
        $migration->down();
        self::addToAssertionCount(1);
    }

    #[Test]
    public function ambiguous_usage_status_constraint_is_additive_and_forward_only(): void
    {
        $path = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000500_allow_ambiguous_ai_usage_status.php';
        $source = file_get_contents($path);
        $migration = require $path;

        self::assertInstanceOf(Migration::class, $migration);
        self::assertIsString($source);
        self::assertStringContainsString("'ambiguous'", $source);
        self::assertStringContainsString('eg_usage_status_ck', $source);
        self::assertStringContainsString('eg_usage_status_http_ck', $source);
        self::assertStringNotContainsString('DROP TABLE', strtoupper($source));
        $migration->down();
        self::addToAssertionCount(1);
    }
}
