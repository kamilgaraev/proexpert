<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PipelineRecoveryContractTest extends TestCase
{
    #[Test]
    public function generation_job_payload_is_bounded_and_carries_exact_stage_identity(): void
    {
        $constructor = (new ReflectionClass(GenerateEstimateDraftJob::class))->getConstructor();
        self::assertNotNull($constructor);
        $types = array_map(static fn ($parameter): string => (string) $parameter->getType(), $constructor->getParameters());

        self::assertSame(['int', 'int', 'string', FailureExecutionSnapshot::class, ProcessingStage::class], $types);
    }

    #[Test]
    public function recovery_is_bounded_two_query_wakeup_and_is_scheduled(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration';
        $recovery = file_get_contents($root.'/Application/Generation/RecoverEstimateGenerationPipelines.php');
        $provider = file_get_contents($root.'/EstimateGenerationServiceProvider.php');

        self::assertIsString($recovery);
        self::assertStringContainsString('private const BATCH_SIZE = 100', $recovery);
        self::assertSame(2, substr_count($recovery, '::query()'));
        self::assertStringContainsString('ProcessingStage::cases()', $recovery);
        self::assertStringContainsString('->afterCommit()', $recovery);
        self::assertIsString($provider);
        self::assertStringContainsString('new RecoverEstimateGenerationPipelinesJob', $provider);
        self::assertStringContainsString('->everyMinute()', $provider);
    }
}
