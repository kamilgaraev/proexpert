<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

final class RagJobDispatcher
{
    public function __construct(private readonly Dispatcher $bus, private readonly LoggerInterface $logger) {}

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
