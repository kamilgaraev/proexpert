<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryMigrationContractTest extends TestCase
{
    #[Test]
    public function jsonb_rollout_uses_bounded_shadow_backfill_and_one_explicit_swap_lock(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_000250_convert_session_payloads_to_jsonb.php');

        self::assertIsString($source);
        self::assertStringContainsString('public $withinTransaction = false', $source);
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $source);
        self::assertStringContainsString('LIMIT 1000', $source);
        self::assertStringContainsString('statement_timeout', $source);
        self::assertStringContainsString('lock_timeout', $source);
        self::assertSame(1, substr_count($source, 'LOCK TABLE estimate_generation_sessions IN ACCESS EXCLUSIVE MODE'));
        self::assertStringNotContainsString('ALTER COLUMN {$column} TYPE', $source);
        self::assertStringContainsString('BEFORE INSERT OR UPDATE', $source);
        self::assertStringContainsString('IS DISTINCT FROM', $source);
        self::assertStringContainsString('payload_rollout_ambiguous', $source);
        self::assertStringContainsString('cleanupOldColumns', $source);
        self::assertStringNotContainsString('SET statement_timeout', $source);
        self::assertStringNotContainsString('SET lock_timeout', $source);
        self::assertStringContainsString('SET LOCAL statement_timeout', $source);
        self::assertStringContainsString('SET LOCAL lock_timeout', $source);
    }
}
