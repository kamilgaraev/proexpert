<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildJobHandler;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\RunTargetedPackageRebuildJob;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunTargetedPackageRebuildJobTest extends TestCase
{
    #[Test]
    public function it_delegates_the_durable_operation_identifier_to_the_dedicated_handler(): void
    {
        $job = new RunTargetedPackageRebuildJob('018f809a-e85e-7382-b419-00f5a7d7ab59');
        $handler = new RecordingTargetedPackageRebuildJobHandler;

        $job->handle($handler);

        self::assertSame(['018f809a-e85e-7382-b419-00f5a7d7ab59'], $handler->operationIds);
    }

    #[Test]
    public function it_keeps_the_worker_outside_the_mass_generation_path(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Jobs/RunTargetedPackageRebuildJob.php',
        );

        foreach (['GenerateEstimateDraftJob', 'RebuildGeneratedSection', 'DraftPipelineEntrypoint', 'PublishValidatedDraft'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    #[Test]
    public function it_dispatches_the_targeted_job_to_its_queue_only_after_the_publish_transaction_commits(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Jobs/LaravelTargetedPackageRebuildJobScheduler.php',
        );
        $dispatch = strpos($source, 'RunTargetedPackageRebuildJob::dispatch');
        $connection = strpos($source, '->onConnection(');
        $queue = strpos($source, '->onQueue(');
        $afterCommit = strpos($source, '->afterCommit()');

        self::assertNotFalse($dispatch);
        self::assertNotFalse($connection);
        self::assertNotFalse($queue);
        self::assertNotFalse($afterCommit);
        self::assertTrue($dispatch < $connection && $connection < $queue && $queue < $afterCommit);
    }

    #[Test]
    public function it_binds_the_dedicated_job_to_the_safe_targeted_executor(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php',
        );

        self::assertStringContainsString('TargetedPackageRebuildJobHandler::class', $source);
        self::assertStringContainsString('new RunTargetedPackageRebuildOperation(', $source);
    }
}

final class RecordingTargetedPackageRebuildJobHandler implements TargetedPackageRebuildJobHandler
{
    /** @var list<string> */
    public array $operationIds = [];

    public function handle(string $operationId): void
    {
        $this->operationIds[] = $operationId;
    }
}
