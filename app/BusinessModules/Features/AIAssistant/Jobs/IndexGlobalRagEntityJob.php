<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class IndexGlobalRagEntityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public bool $failOnTimeout = true;
    public ?int $eventId = null;
    public ?int $eventRevision = null;

    public function __construct(public string $sourceType, public string $entityType, public string|int $entityId, public int $afterOrganizationId = 0, ?int $eventId = null, ?int $eventRevision = null)
    {
        $this->eventId = $eventId;
        $this->eventRevision = $eventRevision;
        $this->onConnection((string) config('ai-assistant.rag.queue_connection', 'redis_ai_rag'));
        $this->onQueue((string) config('ai-assistant.rag.live_queue', 'ai-rag-live'));
    }

    public function handle(RagIndexingCoordinator $coordinator, ?GlobalRagQueue $queue = null): void
    {
        $queue ??= app(GlobalRagQueue::class);
        if ($this->eventId === null) {
            $queue->record($this->sourceType, $this->entityType, $this->entityId, $this->afterOrganizationId);
            return;
        }
        $event = $queue->claim($this->eventId, $this->eventRevision);
        if ($event === null) {
            return;
        }
        try {
            $queue->processBatch($event, $coordinator);
        } catch (Throwable $exception) {
            $queue->release($event, $exception);
            throw $exception;
        }
    }
}
