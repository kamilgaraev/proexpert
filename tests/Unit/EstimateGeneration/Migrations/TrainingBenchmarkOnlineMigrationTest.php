<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Migrations;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrainingBenchmarkOnlineMigrationTest extends TestCase
{
    #[Test]
    public function populated_training_phases_are_non_transactional_bounded_and_online_safe(): void
    {
        $root = dirname(__DIR__, 4);
        $paths = glob($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_00{17,18,19,20,21,22}*.php', GLOB_BRACE);
        self::assertIsArray($paths);
        self::assertCount(6, $paths);

        $source = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $paths));
        foreach ($paths as $path) {
            self::assertStringContainsString('public $withinTransaction = false;', (string) file_get_contents($path), basename($path));
        }

        self::assertStringNotContainsString('DB::statement("UPDATE ', $source);
        self::assertStringNotContainsString("DB::statement('UPDATE ", $source);
        self::assertDoesNotMatchRegularExpression('/CREATE (?:UNIQUE )?INDEX (?!CONCURRENTLY)/', $source);
        self::assertStringContainsString('NOT VALID', $source);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $source);
        self::assertStringContainsString("SET lock_timeout = '5s'", $source);
        self::assertStringContainsString("SET statement_timeout = '15min'", $source);
    }
}
