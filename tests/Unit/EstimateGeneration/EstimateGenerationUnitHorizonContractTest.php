<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\RecoverEstimateGenerationUnitsJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\RunEstimateGenerationBenchmarkJob;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationUnitHorizonContractTest extends TestCase
{
    #[Test]
    public function production_and_local_have_dedicated_unit_supervisors(): void
    {
        $config = require dirname(__DIR__, 3).'/config/horizon.php';
        foreach (['production', 'local'] as $environment) {
            $unitSupervisor = $config['environments'][$environment]['supervisor-estimate-generation-units'];
            $maintenanceSupervisor = $config['environments'][$environment]['supervisor-estimate-generation-unit-maintenance'];

            self::assertSame(ProcessEstimateGenerationUnitJob::CONNECTION, $unitSupervisor['connection']);
            self::assertSame([ProcessEstimateGenerationUnitJob::QUEUE], $unitSupervisor['queue']);
            self::assertSame((new ProcessEstimateGenerationUnitJob(1, 'source'))->tries, $unitSupervisor['tries']);
            self::assertGreaterThanOrEqual((new ProcessEstimateGenerationUnitJob(1, 'source'))->timeout, $unitSupervisor['timeout']);
            self::assertSame(512, $unitSupervisor['memory']);
            self::assertSame(RecoverEstimateGenerationUnitsJob::CONNECTION, $maintenanceSupervisor['connection']);
            self::assertSame([RecoverEstimateGenerationUnitsJob::QUEUE], $maintenanceSupervisor['queue']);
            self::assertSame((new RecoverEstimateGenerationUnitsJob)->tries, $maintenanceSupervisor['tries']);
            self::assertGreaterThanOrEqual((new RecoverEstimateGenerationUnitsJob)->timeout, $maintenanceSupervisor['timeout']);
            self::assertSame(256, $maintenanceSupervisor['memory']);
        }

        self::assertGreaterThanOrEqual(1, $config['environments']['production']['supervisor-estimate-generation-units']['minProcesses']);
        self::assertGreaterThanOrEqual(1, $config['environments']['production']['supervisor-estimate-generation-units']['maxProcesses']);
        self::assertGreaterThanOrEqual(1, $config['environments']['local']['supervisor-estimate-generation-units']['processes']);
        self::assertGreaterThanOrEqual(1, $config['environments']['local']['supervisor-estimate-generation-unit-maintenance']['processes']);
        self::assertArrayHasKey('redis_estimate_generation:'.ProcessEstimateGenerationUnitJob::QUEUE, $config['waits']);
        self::assertArrayHasKey('redis_estimate_generation:'.RecoverEstimateGenerationUnitsJob::QUEUE, $config['waits']);
    }

    #[Test]
    public function unit_and_recovery_jobs_never_fall_back_to_default_queue(): void
    {
        $unit = new ProcessEstimateGenerationUnitJob(1, 'source');
        $recovery = new RecoverEstimateGenerationUnitsJob;

        self::assertSame(ProcessEstimateGenerationUnitJob::CONNECTION, $unit->connection);
        self::assertSame(ProcessEstimateGenerationUnitJob::QUEUE, $unit->queue);
        self::assertSame(RecoverEstimateGenerationUnitsJob::CONNECTION, $recovery->connection);
        self::assertSame(RecoverEstimateGenerationUnitsJob::QUEUE, $recovery->queue);
        self::assertSame(20, $unit->tries);
        self::assertSame(3, $recovery->tries);
    }

    #[Test]
    public function benchmark_job_has_an_isolated_connection_and_safe_timeout_ordering(): void
    {
        $horizon = require dirname(__DIR__, 3).'/config/horizon.php';
        $queue = require dirname(__DIR__, 3).'/config/queue.php';
        $job = new RunEstimateGenerationBenchmarkJob(1, 'benchmark-key');

        self::assertSame(RunEstimateGenerationBenchmarkJob::CONNECTION, $job->connection);
        self::assertSame(RunEstimateGenerationBenchmarkJob::QUEUE, $job->queue);
        self::assertArrayHasKey(RunEstimateGenerationBenchmarkJob::CONNECTION, $queue['connections']);
        self::assertSame(
            RunEstimateGenerationBenchmarkJob::QUEUE,
            $queue['connections'][RunEstimateGenerationBenchmarkJob::CONNECTION]['queue'],
        );

        foreach (['production', 'local'] as $environment) {
            $supervisor = $horizon['environments'][$environment]['supervisor-estimate-generation-benchmarks'];
            self::assertSame(RunEstimateGenerationBenchmarkJob::CONNECTION, $supervisor['connection']);
            self::assertSame([RunEstimateGenerationBenchmarkJob::QUEUE], $supervisor['queue']);
            self::assertGreaterThan($job->timeout, $supervisor['timeout']);
            self::assertGreaterThan(
                $supervisor['timeout'],
                $queue['connections'][RunEstimateGenerationBenchmarkJob::CONNECTION]['retry_after'],
            );
        }

        self::assertArrayHasKey(
            RunEstimateGenerationBenchmarkJob::CONNECTION.':'.RunEstimateGenerationBenchmarkJob::QUEUE,
            $horizon['waits'],
        );
    }
}
