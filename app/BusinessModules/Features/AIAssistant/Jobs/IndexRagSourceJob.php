<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class IndexRagSourceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $organizationId,
        public ?int $projectId = null,
        public ?string $sourceType = null,
        public ?int $runId = null
    ) {
        $this->onQueue($this->queueName());
    }

    public function handle(RagIndexer $indexer, RagIndexingCoordinator $coordinator): void
    {
        if ($this->runId !== null) {
            $coordinator->markRunning($this->runId);
        }

        $indexed = $indexer->indexOrganization($this->organizationId, $this->projectId, $this->sourceType);

        if ($this->runId !== null) {
            $coordinator->markSucceeded($this->runId, $indexed);
        }
    }

    public function failed(Throwable $throwable): void
    {
        if ($this->runId !== null) {
            try {
                app(RagIndexingCoordinator::class)->markFailed($this->runId, $throwable);
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
}
