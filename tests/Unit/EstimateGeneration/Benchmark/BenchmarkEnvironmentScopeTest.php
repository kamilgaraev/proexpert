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

        self::assertStringContainsString("environment(['local', 'testing'])", $provider);
        self::assertStringContainsString("is_dir(base_path('tests/Fixtures/EstimateGeneration/benchmarks'))", $provider);
        self::assertStringContainsString('if ($this->repositoryReplayEnabled())', $provider);
    }
}
