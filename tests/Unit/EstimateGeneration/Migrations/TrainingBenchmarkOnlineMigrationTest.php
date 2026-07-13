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

        $applicability = [
            '2026_07_12_001700_rebuild_estimate_generation_training_and_benchmarks.php' => ['ensureConstraint', 'validateConstraint'],
            '2026_07_12_001800_harden_estimate_generation_training_and_benchmarks.php' => ['ensureConstraint', 'validateConstraint', 'swapValidatedConstraint'],
            '2026_07_12_001900_close_training_benchmark_edge_contracts.php' => ['swapValidatedConstraint'],
            '2026_07_12_002000_enforce_training_benchmark_storage_contracts.php' => ['ensureConstraint', 'validateConstraint', 'swapValidatedConstraint'],
            '2026_07_12_002100_finalize_training_benchmark_architecture.php' => ['ensureConstraint', 'validateConstraint'],
            '2026_07_12_002200_close_training_benchmark_races.php' => ['swapValidatedConstraint'],
        ];

        $source = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $paths));
        foreach ($paths as $path) {
            $phase = (string) file_get_contents($path);
            self::assertStringContainsString('public $withinTransaction = false;', $phase, basename($path));
            self::assertStringContainsString('try {', $phase, basename($path));
            self::assertStringContainsString('finally {', $phase, basename($path));
            self::assertStringContainsString('restoreSessionTimeouts', $phase, basename($path));
            $up = explode('public function down(): void', $phase, 2)[0];
            self::assertDoesNotMatchRegularExpression('/DROP CONSTRAINT (?!IF EXISTS )/', $up, basename($path));
            self::assertStringNotContainsString('DROP CONSTRAINT eg_benchmark_closed_state_chk', $up, basename($path));
            self::assertStringContainsString('checkpoint(', $up, basename($path));
            foreach ($applicability[basename($path)] as $requiredHelper) {
                self::assertStringContainsString($requiredHelper.'(', $up, basename($path).' must use '.$requiredHelper);
            }
        }

        self::assertStringNotContainsString('DB::statement("UPDATE ', $source);
        self::assertStringNotContainsString("DB::statement('UPDATE ", $source);
        self::assertDoesNotMatchRegularExpression('/CREATE (?:UNIQUE )?INDEX (?!CONCURRENTLY)/', $source);
        self::assertStringContainsString('configureSessionTimeouts', $source);

        $runtime = (string) file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Support/TrainingBenchmarkOnlineMigrationRuntime.php');
        self::assertStringContainsString('ESTIMATE_CONTRACT_INTERRUPT_ORDINAL', $runtime);
        self::assertStringNotContainsString('ESTIMATE_CONTRACT_INTERRUPT_AFTER', $runtime);
        self::assertStringContainsString('observedCheckpointCount', $runtime);
        self::assertStringNotContainsString('function runIdempotentPhase(', $runtime);
        self::assertStringNotContainsString('public function backfill(', $runtime);

        $runner = (string) file_get_contents($root.'/tests/Runtime/run-training-benchmark-contract.ps1');
        self::assertStringContainsString('ESTIMATE_CONTRACT_INTERRUPT_ORDINAL', $runner);
        self::assertStringContainsString('ESTIMATE_CONTRACT_CHECKPOINT_COUNT:', $runner);
    }
}
