<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RagJobDispatcherTest extends TestCase
{
    public function test_batch_with_different_queues_is_rejected_before_delivery(): void
    {
        $jobs = [new IndexRagSourceJob(3, 4, 'design_additional', 7, 'design_ifc_model_element', 12),
            new IndexRagSourceJob(3, 4, 'design_additional', 8, 'design_ifc_model_element', 13)];
        $jobs[1]->onQueue('another-queue');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $failure = null;
        self::assertFalse((new RagJobDispatcher($this->createMock(Dispatcher::class), $logger))->dispatchMany(
            $jobs, static function (\Throwable $error) use (&$failure): void { $failure = $error; },
        ));
        self::assertInstanceOf(\InvalidArgumentException::class, $failure);
    }

    public function test_batch_delivery_uses_one_queue_bulk_call(): void
    {
        $jobs = [new IndexRagSourceJob(3, 4, 'design_additional', 7, 'design_ifc_model_element', 12),
            new IndexRagSourceJob(3, 4, 'design_additional', 8, 'design_ifc_model_element', 13)];
        $queue = $this->createMock(QueueContract::class);
        $queue->expects(self::once())->method('bulk')->with($jobs, '', $jobs[0]->queue);
        $factory = $this->createMock(Factory::class);
        $factory->expects(self::once())->method('connection')->with($jobs[0]->connection)->willReturn($queue);
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects(self::never())->method('dispatch');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $previous = Facade::getFacadeApplication();
        Facade::setFacadeApplication(new Container);
        Queue::swap($factory);
        try {
            self::assertTrue((new RagJobDispatcher($bus, $logger))->dispatchMany($jobs, static function (): void {
                throw new \LogicException('Unexpected batch failure');
            }));
        } finally {
            Queue::clearResolvedInstance('queue');
            Facade::setFacadeApplication($previous);
        }
    }

    public function test_batch_outage_keeps_failure_recoverable_without_exposing_secrets(): void
    {
        $job = new IndexRagSourceJob(3, 4, 'design_additional', 7, 'design_ifc_model_element', 12);
        $exception = new \RuntimeException('redis://private-password@private-host');
        $queue = $this->createMock(QueueContract::class);
        $queue->expects(self::once())->method('bulk')->willThrowException($exception);
        $factory = $this->createMock(Factory::class);
        $factory->method('connection')->willReturn($queue);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('ai_assistant.rag.batch_dispatch_failed', [
            'run_id' => 7, 'organization_id' => 3, 'job_class' => IndexRagSourceJob::class,
            'exception_class' => \RuntimeException::class,
        ]);
        $previous = Facade::getFacadeApplication();
        Facade::setFacadeApplication(new Container);
        Queue::swap($factory);
        $failure = null;
        try {
            self::assertFalse((new RagJobDispatcher($this->createMock(Dispatcher::class), $logger))->dispatchMany(
                [$job], static function (\Throwable $error) use (&$failure): void { $failure = $error; },
            ));
            self::assertSame($exception, $failure);
        } finally {
            Queue::clearResolvedInstance('queue');
            Facade::setFacadeApplication($previous);
        }
    }

    public function test_successful_delivery_does_not_mark_pending_run_as_failed(): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $logger = $this->createMock(LoggerInterface::class);
        $job = new IndexRagSourceJob(3, null, 'estimate', 7, 'estimate_item', 12);
        $bus->expects($this->once())->method('dispatch')->with($job)->willReturn('queue-id');
        $logger->expects($this->never())->method('warning');
        $this->assertTrue((new RagJobDispatcher($bus, $logger))->dispatch($job, static function (): void {
            throw new \LogicException('Unexpected failure callback');
        }));
    }

    public function test_queue_outage_preserves_failure_for_recovery_without_propagating_or_logging_secret_message(): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $logger = $this->createMock(LoggerInterface::class);
        $exception = new \RuntimeException('redis://private-password@private-host');
        $bus->expects($this->once())->method('dispatch')->willThrowException($exception);
        $logger->expects($this->once())->method('warning')->with('ai_assistant.rag.dispatch_failed', [
            'run_id' => 7, 'organization_id' => 3, 'job_class' => IndexRagSourceJob::class, 'exception_class' => \RuntimeException::class]);
        $failure = null;
        $delivered = (new RagJobDispatcher($bus, $logger))->dispatch(new IndexRagSourceJob(3, null, 'estimate', 7), static function (\Throwable $error) use (&$failure): void {
            $failure = $error;
        });
        $this->assertFalse($delivered);
        $this->assertSame($exception, $failure);
    }

    public function test_secondary_status_and_logger_failure_do_not_turn_queue_outage_into_business_exception(): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $logger = $this->createMock(LoggerInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('Queue unavailable'));
        $logger->expects($this->exactly(2))->method('warning')->willThrowException(new \RuntimeException('Logger unavailable'));
        $this->assertFalse((new RagJobDispatcher($bus, $logger))->dispatch(new IndexRagSourceJob(3, null, 'estimate', 7), static function (): void {
            throw new \RuntimeException('Database unavailable');
        }));
    }
}
