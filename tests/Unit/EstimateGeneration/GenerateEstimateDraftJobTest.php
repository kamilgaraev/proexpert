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
use InvalidArgumentException;
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

    public function test_failed_does_not_start_failure_recovery_for_stale_state(): void
    {
        $failureHandler = new class
        {
            public int $calls = 0;

            public function handle(FailureExecutionSnapshot $snapshot, Throwable $error): void
            {
                $this->calls++;
            }
        };
        $container = new Container;
        $container->instance(HandleEstimateGenerationDraftFailure::class, $failureHandler);
        Container::setInstance($container);

        $this->job()->failed(new StaleEstimateGenerationState(10, 2));

        self::assertSame(0, $failureHandler->calls);
    }

    public function test_failed_starts_failure_recovery_for_regular_error(): void
    {
        $failureHandler = new class
        {
            public int $calls = 0;

            public function handle(FailureExecutionSnapshot $snapshot, Throwable $error): void
            {
                $this->calls++;
            }
        };
        $container = new Container;
        $container->instance(HandleEstimateGenerationDraftFailure::class, $failureHandler);
        Container::setInstance($container);

        $this->job()->failed(new RuntimeException('pipeline failed'));

        self::assertSame(1, $failureHandler->calls);
    }

    public function test_queue_delivery_is_not_deleted_when_recovery_does_not_commit(): void
    {
        $previousContainer = Container::getInstance();
        try {
            foreach ([false, true] as $throws) {
                $container = new Container;
                Container::setInstance($container);
                $container->instance(HandleEstimateGenerationDraftFailure::class, new class($throws)
                {
                    public function __construct(private bool $throws) {}

                    public function handle(FailureExecutionSnapshot $snapshot, \Throwable $error): bool
                    {
                        if ($this->throws) {
                            throw new \RuntimeException('recovery unavailable');
                        }

                        return false;
                    }
                });
                $overflow = new InvalidArgumentException('estimate_work_items_exceed_approved_plan');
                $generation = $this->throwingGeneration($overflow);
                $job = $this->job()->withFakeQueueInteractions();
                try {
                    $job->handle($generation);
                    self::fail('An uncommitted recovery deleted the delivery.');
                } catch (\Throwable $error) {
                    if ($throws) {
                        self::assertSame('recovery unavailable', $error->getMessage());
                    } else {
                        self::assertSame($overflow, $error);
                    }
                }
                $job->assertNotDeleted()->assertNotReleased()->assertNotFailed();
            }
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    public function test_nonoverflow_exceptions_keep_the_original_queue_failure(): void
    {
        foreach ([new InvalidArgumentException('estimate_work_approved_plan_invalid'), new \RuntimeException('transport failed')] as $error) {
            $job = $this->job()->withFakeQueueInteractions();
            try {
                $job->handle($this->throwingGeneration($error));
                self::fail('A technical failure was swallowed.');
            } catch (\Throwable $actual) {
                self::assertSame($error, $actual);
            }
            $job->assertNotDeleted()->assertNotReleased()->assertNotFailed();
        }
    }

    private function throwingGeneration(\Throwable $error): RunEstimateGenerationDraft
    {
        $generation = $this->createMock(RunEstimateGenerationDraft::class);
        $generation->expects(self::once())->method('handle')->willThrowException($error);

        return $generation;
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
