<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PipelineRecoveryContractTest extends TestCase
{
    #[Test]
    public function generation_job_payload_is_bounded_and_stage_is_derived_from_checkpoint(): void
    {
        $constructor = (new ReflectionClass(GenerateEstimateDraftJob::class))->getConstructor();
        self::assertNotNull($constructor);
        $types = array_map(static fn ($parameter): string => (string) $parameter->getType(), $constructor->getParameters());

        self::assertSame(['int', 'int', 'string', FailureExecutionSnapshot::class], $types);

        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration';
        $job = file_get_contents($root.'/Jobs/GenerateEstimateDraftJob.php');
        $failure = file_get_contents($root.'/Application/Generation/HandleEstimateGenerationDraftFailure.php');
        self::assertIsString($job);
        self::assertIsString($failure);
        self::assertStringNotContainsString('PipelineCheckpointStore', $job);
        self::assertStringContainsString("->where('status', 'running')", $failure);
        self::assertStringContainsString('stage: $checkpoint->stage', $failure);
    }

    #[Test]
    public function recovery_is_bounded_cursor_fair_lease_aware_and_is_scheduled(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration';
        $recovery = file_get_contents($root.'/Application/Generation/RecoverEstimateGenerationPipelines.php');
        $provider = file_get_contents($root.'/EstimateGenerationServiceProvider.php');

        self::assertIsString($recovery);
        self::assertStringContainsString('private const BATCH_SIZE = 100', $recovery);
        self::assertStringContainsString("where('id', '>', \$cursor)", $recovery);
        self::assertStringContainsString("where('id', '<=', \$cursor)", $recovery);
        self::assertStringContainsString(
            "->where('generation_attempt_id', \$snapshot->attemptId)",
            file_get_contents($root.'/Application/Generation/HandleEstimateGenerationDraftFailure.php'),
        );
        self::assertStringContainsString('CheckpointStatus::Running', $recovery);
        self::assertStringContainsString('lease_expires_at->toDateTimeImmutable() > $now', $recovery);
        self::assertStringContainsString('saveCursor', $recovery);
        self::assertStringContainsString('->afterCommit()', $recovery);
        self::assertIsString($provider);
        self::assertStringContainsString('new RecoverEstimateGenerationPipelinesJob', $provider);
        self::assertStringContainsString('->everyMinute()', $provider);
    }
}
