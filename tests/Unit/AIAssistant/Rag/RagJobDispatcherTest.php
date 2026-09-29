<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RagJobDispatcherTest extends TestCase
{
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
