<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

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
        public ?string $sourceType = null
    ) {
        $this->onQueue($this->queueName());
    }

    public function handle(RagIndexer $indexer): void
    {
        $indexer->indexOrganization($this->organizationId, $this->projectId, $this->sourceType);
    }

    public function failed(Throwable $throwable): void
    {
        Log::warning('ai_assistant.rag.index_job_failed', [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'source_type' => $this->sourceType,
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
