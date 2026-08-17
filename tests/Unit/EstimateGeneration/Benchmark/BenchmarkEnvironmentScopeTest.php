<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkEnvironmentScopeTest extends TestCase
{
    #[Test]
    public function production_does_not_register_repository_fixture_replay(): void
    {
        $provider = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        self::assertIsString($provider);
        $provider = str_replace("\r\n", "\n", $provider);

        self::assertStringContainsString(implode("\n", [
            "            base_path('tests/Fixtures/EstimateGeneration/benchmarks'),",
            '            [],',
        ]), $provider);
        self::assertStringNotContainsString('ProductionReplayBenchmarkAdapter', $provider);
        self::assertStringNotContainsString('repositoryReplayEnabled', $provider);
        self::assertStringNotContainsString('benchmark.registered_manifests', $provider);
    }
}
