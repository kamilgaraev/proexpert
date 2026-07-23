<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\HandleEstimateGenerationDraftFailure;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class GenerateEstimateDraftJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance();

        parent::tearDown();
    }

    public function test_handle_swallows_stale_state_error(): void
    {
        $generation = $this->createMock(RunEstimateGenerationDraft::class);
        $generation->expects(self::once())
            ->method('handle')
            ->willThrowException(new StaleEstimateGenerationState(10, 2));

        $this->job()->handle($generation);

        self::assertTrue(true);
    }

    public function test_handle_does_not_hide_regular_error(): void
    {
        $error = new RuntimeException('pipeline failed');
        $generation = $this->createMock(RunEstimateGenerationDraft::class);
        $generation->expects(self::once())->method('handle')->willThrowException($error);

        $this->expectExceptionObject($error);

        $this->job()->handle($generation);
    }

    public function test_failed_does_not_start_failure_workflow_for_stale_state(): void
    {
        $failureHandler = new class
        {
            public int $calls = 0;

            public function handle(FailureExecutionSnapshot $snapshot, Throwable $error): void
            {
                $this->calls++;
            }
        };
        $container = new Container();
        $container->instance(HandleEstimateGenerationDraftFailure::class, $failureHandler);
        Container::setInstance($container);

        $this->job()->failed(new StaleEstimateGenerationState(10, 2));

        self::assertSame(0, $failureHandler->calls);
    }

    public function test_failed_starts_failure_workflow_for_regular_error(): void
    {
        $failureHandler = new class
        {
            public int $calls = 0;

            public function handle(FailureExecutionSnapshot $snapshot, Throwable $error): void
            {
                $this->calls++;
            }
        };
        $container = new Container();
        $container->instance(HandleEstimateGenerationDraftFailure::class, $failureHandler);
        Container::setInstance($container);

        $this->job()->failed(new RuntimeException('pipeline failed'));

        self::assertSame(1, $failureHandler->calls);
    }

    private function job(): GenerateEstimateDraftJob
    {
        $snapshot = new FailureExecutionSnapshot(
            organizationId: 1,
            projectId: 2,
            sessionId: 10,
            stateVersion: 2,
            status: 'generating',
            attemptId: 'attempt-1',
            eventId: 'event-1',
            correlationId: 'correlation-1',
        );

        return new GenerateEstimateDraftJob(10, 2, 'attempt-1', $snapshot);
    }
}
