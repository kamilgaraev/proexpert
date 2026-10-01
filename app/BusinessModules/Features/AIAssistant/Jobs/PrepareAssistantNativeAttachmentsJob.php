<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeAttachmentPreparationQueue;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileMetadata;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class PrepareAssistantNativeAttachmentsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const PAGE_SIZE = 5;

    private const PREPARABLE_RUN_STATUSES = [
        RagIndexRun::STATUS_QUEUED,
        RagIndexRun::STATUS_RUNNING,
        RagIndexRun::STATUS_SUCCEEDED,
        RagIndexRun::STATUS_FAILED,
    ];

    public int $tries = 3;

    public int $timeout = 80;

    public int $uniqueFor = 600;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $runId,
        public int $organizationId,
        public string $sourceType,
        public string $dispatchToken,
        public int $dispatchCycle = 1,
        public int $nativeTypeIndex = 0,
        public ?string $afterSourceId = null,
    ) {
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [5, 20, 60];
    }

    public function uniqueId(): string
    {
        return implode(':', [
            $this->runId,
            $this->sourceType,
            $this->nativeTypeIndex,
            $this->afterSourceId ?? 'first',
            $this->dispatchToken,
        ]);
    }

    public function handle(
        AssistantLegalNativeFileIndexer $legal,
        AssistantOperationsNativeFileIndexer $operations,
        AssistantNativeAttachmentPreparationQueue $queue,
    ): void {
        if ($this->organizationId < 1 || ! in_array($this->sourceType, ['legal_business', 'operations_quality'], true)) {
            $queue->releaseDispatch($this->runId, $this->dispatchToken);

            return;
        }

        $run = RagIndexRun::query()->whereKey($this->runId)
            ->where('organization_id', $this->organizationId)
            ->where('source_type', $this->sourceType)
            ->whereIn('status', self::PREPARABLE_RUN_STATUSES)
            ->whereNull('project_id')
            ->whereNull('entity_type')
            ->first();
        if (! $run instanceof RagIndexRun) {
            $queue->releaseDispatch($this->runId, $this->dispatchToken);

            return;
        }
        if (! $queue->renewDispatch($this->runId, $this->dispatchToken)) {
            return;
        }

        $pageTypeIndex = max(0, $this->nativeTypeIndex);
        $pageAfterSourceId = $this->afterSourceId;
        $shouldMarkComplete = false;
        $queue->withPageLock($this->runId, $this->sourceType, $pageTypeIndex, $pageAfterSourceId, function () use ($legal, $operations, $queue, $pageTypeIndex, $pageAfterSourceId, &$shouldMarkComplete): void {
            $progress = $queue->pageProgress($this->runId, $this->sourceType, $pageTypeIndex, $pageAfterSourceId);
            if (is_array($progress)) {
                if (($progress['state'] ?? null) === 'complete') {
                    $shouldMarkComplete = true;

                    return;
                }

                if ($queue->resumePageContinuation(
                    $this->runId,
                    $this->sourceType,
                    $pageTypeIndex,
                    $pageAfterSourceId,
                    $this->dispatchToken,
                    fn (int $nextTypeIndex, ?string $nextAfterSourceId) => $this->dispatchContinuation($nextTypeIndex, $nextAfterSourceId),
                )) {
                    return;
                }
            }

            $this->preparePage($legal, $operations, $queue, $pageTypeIndex, $pageAfterSourceId, $shouldMarkComplete);
        });
        if ($shouldMarkComplete) {
            $queue->markComplete($this->runId, $this->dispatchToken);
        }
    }

    private function preparePage(
        AssistantLegalNativeFileIndexer $legal,
        AssistantOperationsNativeFileIndexer $operations,
        AssistantNativeAttachmentPreparationQueue $queue,
        int $pageTypeIndex,
        ?string $pageAfterSourceId,
        bool &$shouldMarkComplete,
    ): void {
        $types = $this->nativeTypes();
        $typeIndex = $pageTypeIndex;
        $afterSourceId = $pageAfterSourceId;
        $processed = 0;

        while ($typeIndex < count($types)) {
            $nativeType = $types[$typeIndex];
            $query = $this->sourceQuery($nativeType);
            if ($afterSourceId !== null) {
                $query->where('native_source.id', '>', $afterSourceId);
            }

            $remaining = self::PAGE_SIZE - $processed;
            if ($remaining < 1) {
                $this->checkpointAndDispatch($queue, $pageTypeIndex, $pageAfterSourceId, $typeIndex, $afterSourceId);

                return;
            }

            $ids = $query->selectRaw('native_source.id as source_id')
                ->orderBy('native_source.id')
                ->limit($remaining + 1)
                ->pluck('source_id');
            $hasMore = $ids->count() > $remaining;
            $batch = $ids->take($remaining);

            foreach ($batch as $sourceId) {
                $sourceId = (string) $sourceId;
                $this->prepareOne($nativeType, $sourceId, $legal, $operations);
                $processed++;
                $afterSourceId = $sourceId;
            }

            if ($hasMore) {
                $this->checkpointAndDispatch($queue, $pageTypeIndex, $pageAfterSourceId, $typeIndex, $afterSourceId);

                return;
            }

            $typeIndex++;
            $afterSourceId = null;
        }

        $queue->markPageComplete($this->runId, $this->sourceType, $pageTypeIndex, $pageAfterSourceId);
        $shouldMarkComplete = true;
    }

    private function checkpointAndDispatch(
        AssistantNativeAttachmentPreparationQueue $queue,
        int $pageTypeIndex,
        ?string $pageAfterSourceId,
        int $nextTypeIndex,
        ?string $nextAfterSourceId,
    ): void {
        $queue->checkpointPageContinuation(
            $this->runId,
            $this->sourceType,
            $pageTypeIndex,
            $pageAfterSourceId,
            $nextTypeIndex,
            $nextAfterSourceId,
            $this->dispatchToken,
            fn (int $nextIndex, ?string $nextId) => $this->dispatchContinuation($nextIndex, $nextId),
        );
    }

    public function failed(Throwable $exception): void
    {
        $retryScheduled = false;
        try {
            $queue = app(AssistantNativeAttachmentPreparationQueue::class);
            $queue->releaseDispatch($this->runId, $this->dispatchToken);
            if ($this->dispatchCycle < 3) {
                $retryScheduled = $queue->dispatchRetryRun($this->runId, $this->organizationId, $this->sourceType);
            }
        } catch (Throwable $dispatchException) {
            Log::warning('ai_assistant.native_attachment_preparation_retry_failed', [
                'run_id' => $this->runId,
                'exception_class' => $dispatchException::class,
            ]);
        }
        Log::warning('ai_assistant.native_attachment_preparation_failed', [
            'run_id' => $this->runId,
            'organization_id' => $this->organizationId,
            'source_type' => $this->sourceType,
            'dispatch_cycle' => $this->dispatchCycle,
            'retry_scheduled' => $retryScheduled,
            'retry_cycles_exhausted' => $this->dispatchCycle >= 3,
            'exception_class' => $exception::class,
        ]);
    }

    private function prepareOne(
        string $nativeType,
        string $sourceId,
        AssistantLegalNativeFileIndexer $legal,
        AssistantOperationsNativeFileIndexer $operations,
    ): void {
        if ($this->sourceType === 'legal_business') {
            try {
                $legal->prepare($this->organizationId, null, $nativeType, $sourceId);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'ai_assistant_document_native_source_invalid') {
                    throw $exception;
                }

                Log::notice('ai_assistant.native_attachment_source_rejected', [
                    'run_id' => $this->runId,
                    'organization_id' => $this->organizationId,
                    'native_type' => $nativeType,
                    'source_id' => $sourceId,
                ]);
            }

            return;
        }

        $operations->prepare($this->organizationId, null, $nativeType, $sourceId);
    }

    private function dispatchContinuation(int $nativeTypeIndex, ?string $afterSourceId): void
    {
        self::dispatch(
            $this->runId,
            $this->organizationId,
            $this->sourceType,
            $this->dispatchToken,
            $this->dispatchCycle,
            $nativeTypeIndex,
            $afterSourceId,
        )->onQueue('default');
    }

    private function nativeTypes(): array
    {
        return match ($this->sourceType) {
            'legal_business' => AssistantLegalNativeFileMetadata::types(),
            'operations_quality' => AssistantOperationsNativeFileMetadata::types(),
            default => [],
        };
    }

    private function sourceQuery(string $nativeType): \Illuminate\Database\Query\Builder
    {
        return match ($this->sourceType) {
            'legal_business' => AssistantLegalNativeFileMetadata::sourceQuery($nativeType, $this->organizationId),
            'operations_quality' => AssistantOperationsNativeFileMetadata::sourceQuery($nativeType, $this->organizationId),
            default => throw new RuntimeException('ai_assistant_document_native_source_invalid'),
        };
    }
}
