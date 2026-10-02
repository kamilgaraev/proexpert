<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Exceptions\RagEmbeddingDimensionMismatch;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeAttachmentPreparationQueue;
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

    public ?string $leaseToken = null;

    public function __construct(
        public int $organizationId,
        public ?int $projectId = null,
        public ?string $sourceType = null,
        public ?int $runId = null,
        public ?string $entityType = null,
        public string|int|null $entityId = null
    ) {
        $this->onConnection($this->connectionName());
        $this->onQueue($entityType !== null ? $this->liveQueueName() : $this->queueName());
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
            $this->leaseToken = $run->lease_token;
            app(AssistantNativeAttachmentPreparationQueue::class)->dispatchRunningRun($run);
        }

        $progress = $run instanceof RagIndexRun
            ? function (int $processed) use ($run, $coordinator): void {
                if (! $coordinator->heartbeat($run->id, $run->lease_token, $processed)) {
                    throw new \RuntimeException('RAG indexing lease lost');
                }
            }
            : null;

        try {
            if ($this->sourceType === 'file_document' && $this->entityType === 'file') {
                (new \App\Jobs\RegisterAssistantEntityFile((int) $this->entityId, $this->organizationId))
                    ->handle(app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService::class));
                if ($this->runId !== null) {
                    $coordinator->markSucceeded($this->runId, 0, $run?->lease_token);
                }
                return;
            }
            $prepareNativeAttachmentsInline = $this->shouldPrepareNativeAttachmentsInline();
            if ($prepareNativeAttachmentsInline && ($this->sourceType === null || in_array($this->sourceType, ['operations_quality', 'operations_safety_medical', 'operations_warehouse', 'file_document'], true))) {
                app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileIndexer::class)->prepare(
                    $this->organizationId, $this->projectId, $this->entityType, $this->entityId,
                    $run instanceof RagIndexRun ? static function () use ($run, $coordinator): void {
                        if ($coordinator === null || ! $coordinator->heartbeat($run->id, $run->lease_token)) {
                            throw new \RuntimeException('RAG indexing lease lost');
                        }
                    } : null,
                );
            }
            if ($prepareNativeAttachmentsInline && ($this->sourceType === null || in_array($this->sourceType, ['procurement_business', 'crm_business', 'commercial_proposals_business', 'procurement', 'crm', 'commercial_processes'], true))) {
                app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileIndexer::class)->prepare(
                    $this->organizationId, $this->projectId, $this->entityType, $this->entityId,
                    $run instanceof RagIndexRun ? static function () use ($run, $coordinator): void {
                        if ($coordinator === null || ! $coordinator->heartbeat($run->id, $run->lease_token)) {
                            throw new \RuntimeException('RAG indexing lease lost');
                        }
                    } : null,
                );
            }
            if ($prepareNativeAttachmentsInline && ($this->sourceType === null || in_array($this->sourceType, ['legal_business', 'executive_business'], true))) {
                app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileIndexer::class)->prepare(
                    $this->organizationId, $this->projectId, $this->entityType, $this->entityId,
                    $run instanceof RagIndexRun ? static function () use ($run, $coordinator): void {
                        if ($coordinator === null || ! $coordinator->heartbeat($run->id, $run->lease_token)) {
                            throw new \RuntimeException('RAG indexing lease lost');
                        }
                    } : null,
                );
            }
            if ($prepareNativeAttachmentsInline && (($this->sourceType === 'workforce_payroll' && $this->entityType === 'workforce_export_package_file')
                || ($this->entityType === null && ($this->sourceType === null || $this->sourceType === 'workforce_payroll')))) {
                app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantWorkforceNativeFileIndexer::class)->prepare(
                    $this->organizationId, $this->projectId, $this->entityType === null ? null : $this->entityId,
                    $run instanceof RagIndexRun ? static function () use ($run, $coordinator): void {
                        if ($coordinator === null || !$coordinator->heartbeat($run->id, $run->lease_token)) {
                            throw new \RuntimeException('RAG indexing lease lost');
                        }
                    } : null,
                );
            }
            $indexed = $this->entityType !== null && $this->entityId !== null
            ? $indexer->indexEntity($this->organizationId, $this->sourceType, $this->entityType, $this->entityId, $progress)
            : $indexer->indexOrganization($this->organizationId, $this->projectId, $this->sourceType, $progress);
        } catch (Throwable $exception) {
            if ($exception instanceof RagEmbeddingDimensionMismatch) {
                if ($run instanceof RagIndexRun && $coordinator !== null) {
                    $coordinator->markFailed($run->id, $exception, $run->lease_token);
                }
                $this->fail($exception);

                return;
            }
            if ($run instanceof RagIndexRun && $coordinator !== null) {
                $coordinator->releaseForRetry($run->id, $run->lease_token, $exception);
            }
            throw $exception;
        }

        if ($this->runId !== null) {
            $coordinator ??= app(RagIndexingCoordinator::class);
            $coordinator->markSucceeded($this->runId, $indexed, $run?->lease_token);
        }
    }

    public function failed(Throwable $throwable): void
    {
        if ($this->runId !== null) {
            try {
                $coordinator = app(RagIndexingCoordinator::class);

                $coordinator->markFailed($this->runId, $throwable, $this->leaseToken);
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
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'run_id' => $this->runId,
            'exception_class' => $throwable::class,
        ]);
    }

    private function shouldPrepareNativeAttachmentsInline(): bool
    {
        return app(AssistantNativeAttachmentPreparationQueue::class)->shouldPrepareInline($this->runId);
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

    private function liveQueueName(): string
    {
        try {
            $queue = config('ai-assistant.rag.live_queue', 'ai-rag-live');
        } catch (Throwable) {
            return 'ai-rag-live';
        }

        return is_string($queue) && trim($queue) !== '' ? $queue : 'ai-rag-live';
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
