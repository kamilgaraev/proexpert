<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkAdapterRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\ProductionReplayBenchmarkAdapter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;

final class ProductionReplayBenchmarkLaravelIntegrationTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_cli_registry_resolves_production_replay_adapter(): void
    {
        $adapter = $this->app->make(BenchmarkAdapterRegistry::class)->get('production-replay');

        self::assertInstanceOf(ProductionReplayBenchmarkAdapter::class, $adapter);
        self::assertSame('production-replay', $adapter->id());
    }
}
