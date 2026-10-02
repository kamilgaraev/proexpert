<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Throwable;

final class RagJobDispatcher
{
    public function __construct(private readonly Dispatcher $bus, private readonly LoggerInterface $logger) {}

    public function dispatchMany(array $jobs, callable $onFailure): bool
    {
        if ($jobs === []) {
            return true;
        }

        $jobs = array_values($jobs);
        $first = $jobs[0];
        try {
            foreach ($jobs as $job) {
                if ($job->connection !== $first->connection || $job->queue !== $first->queue) {
                    throw new \InvalidArgumentException('RAG batch jobs must use the same connection and queue.');
                }
            }
            Queue::connection($first->connection)->bulk($jobs, '', $first->queue);

            return true;
        } catch (Throwable $exception) {
            try {
                $onFailure($exception);
            } catch (Throwable $persistenceException) {
                $this->warning('ai_assistant.rag.batch_dispatch_failure_status_failed', $first, $persistenceException);
            }
            $this->warning('ai_assistant.rag.batch_dispatch_failed', $first, $exception);

            return false;
        }
    }

    public function dispatch(IndexRagSourceJob $job, callable $onFailure): bool
    {
        try {
            $this->bus->dispatch($job);
            return true;
        } catch (Throwable $exception) {
            try {
                $onFailure($exception);
            } catch (Throwable $persistenceException) {
                $this->warning('ai_assistant.rag.dispatch_failure_status_failed', $job, $persistenceException);
            }
            $this->warning('ai_assistant.rag.dispatch_failed', $job, $exception);
            return false;
        }
    }

    private function warning(string $event, IndexRagSourceJob $job, Throwable $exception): void
    {
        try {
            $this->logger->warning($event, ['run_id' => $job->runId, 'organization_id' => $job->organizationId,
                'job_class' => $job::class, 'exception_class' => $exception::class]);
        } catch (Throwable) {
        }
    }
}
