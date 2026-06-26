<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class IndexRagSourceJob implements ShouldQueue
{
    use Queueable;

    private const MIN_TIMEOUT_SECONDS = 7200;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $organizationId,
        public ?int $projectId = null,
        public ?string $sourceType = null,
        public ?int $runId = null
    ) {
        $this->onConnection($this->connectionName());
        $this->onQueue($this->queueName());
        $this->tries = $this->configInt('ai-assistant.rag.job_tries', 3);
        $this->timeout = max(
            self::MIN_TIMEOUT_SECONDS,
            $this->configInt('ai-assistant.rag.job_timeout', self::MIN_TIMEOUT_SECONDS)
        );
    }

    public function handle(RagIndexer $indexer, ?RagIndexingCoordinator $coordinator = null): void
    {
        $run = null;

        if ($this->runId !== null) {
            $coordinator ??= app(RagIndexingCoordinator::class);
            $run = $coordinator->markRunning($this->runId);
            if (! $run instanceof RagIndexRun) {
                return;
            }
        }

        if (
            $run instanceof RagIndexRun
            && $run->mode === RagIndexRun::MODE_SCHEDULED
            && $this->projectId === null
            && $this->sourceType !== null
            && ($coordinator ??= app(RagIndexingCoordinator::class))->shouldSplitOrganizationSourceByProjects($this->sourceType)
        ) {
            $coordinator->splitOrganizationSourceRunByProjects($this->runId, $this->organizationId, $this->sourceType);

            return;
        }

        $indexed = $indexer->indexOrganization($this->organizationId, $this->projectId, $this->sourceType);

        if ($this->runId !== null) {
            $coordinator ??= app(RagIndexingCoordinator::class);
            $coordinator->markSucceeded($this->runId, $indexed);
        }
    }

    public function failed(Throwable $throwable): void
    {
        if ($this->runId !== null) {
            try {
                $coordinator = app(RagIndexingCoordinator::class);

                if ($coordinator->splitScheduledOrganizationSourceRunByProjectsIfNeeded($this->runId)) {
                    Log::warning('ai_assistant.rag.index_job_split_after_max_attempts', [
                        'organization_id' => $this->organizationId,
                        'project_id' => $this->projectId,
                        'source_type' => $this->sourceType,
                        'run_id' => $this->runId,
                        'exception_class' => $throwable::class,
                    ]);

                    return;
                }

                $coordinator->markFailed($this->runId, $throwable);
            } catch (Throwable $statusThrowable) {
                Log::warning('ai_assistant.rag.index_run_status_failed', [
                    'run_id' => $this->runId,
                    'exception_class' => $statusThrowable::class,
                ]);
            }
        }

        Log::warning('ai_assistant.rag.index_job_failed', [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'source_type' => $this->sourceType,
            'run_id' => $this->runId,
            'exception_class' => $throwable::class,
        ]);
    }

    private function queueName(): string
    {
        try {
            $queue = config('ai-assistant.rag.queue', 'ai-rag');
        } catch (Throwable) {
            return 'ai-rag';
        }

        return is_string($queue) && trim($queue) !== '' ? $queue : 'ai-rag';
    }

    private function configInt(string $key, int $default): int
    {
        try {
            $value = config($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function connectionName(): string
    {
        try {
            $connection = config('ai-assistant.rag.queue_connection', 'redis_ai_rag');
        } catch (Throwable) {
            return 'redis_ai_rag';
        }

        return is_string($connection) && trim($connection) !== '' ? $connection : 'redis_ai_rag';
    }
}
