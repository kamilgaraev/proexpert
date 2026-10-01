<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RagIndexingCoordinator
{
    private const ASSISTANT_MODULE_SLUG = 'ai-assistant';

    public function __construct(
        private readonly RagIndexer $indexer,
        private readonly ?RagJobDispatcher $dispatcher = null
    ) {}

    /**
     * @return array{queued: int, run_ids: array<int, int>, organization_ids: array<int, int>}
     */
    public function queueAllActiveOrganizations(
        bool $includeInactive = false,
        ?int $limit = null,
        ?int $projectId = null,
        ?string $sourceType = null,
        string $mode = RagIndexRun::MODE_SCHEDULED,
        bool $staleOnly = false,
        ?int $staleAfterHours = null
    ): array {
        $organizations = $this->organizationsForBulkIndex(
            $includeInactive,
            $limit,
            $staleOnly,
            $staleAfterHours,
            $projectId,
            $sourceType
        );
        $runIds = [];
        $organizationIds = [];
        $failedCutoff = $staleOnly
            ? now()->subHours(max(1, (int) config('ai-assistant.rag.failed_retry_after_hours', 12)))
            : null;

        foreach ($organizations as $organization) {
            if (
                $failedCutoff instanceof Carbon
                && $this->hasRecentFailedRun($organization->id, $projectId, $sourceType, $failedCutoff)
            ) {
                continue;
            }

            $run = $this->queueOrganization($organization->id, $projectId, $sourceType, $mode);
            $runIds[] = $run->id;
            $organizationIds[] = $organization->id;
        }

        return [
            'queued' => count($runIds),
            'run_ids' => $runIds,
            'organization_ids' => $organizationIds,
        ];
    }

    /**
     * @return array{queued: int, run_ids: array<int, int>, organization_ids: array<int, int>, project_ids: array<int, int>}
     */
    public function queueAllActiveOrganizationProjects(
        bool $includeInactive = false,
        ?int $limit = null,
        string $sourceType = 'estimate',
        string $mode = RagIndexRun::MODE_SCHEDULED,
        bool $staleOnly = false,
        ?int $staleAfterHours = null
    ): array {
        $organizations = $this->organizationsForBulkIndex(
            $includeInactive,
            $limit,
            false,
            null,
            null,
            $sourceType
        );
        $runIds = [];
        $organizationIds = [];
        $projectIds = [];
        $freshCutoff = $staleOnly
            ? now()->subHours(max(1, $staleAfterHours ?? (int) config('ai-assistant.rag.stale_after_hours', 24)))
            : null;
        $failedCutoff = $staleOnly
            ? now()->subHours(max(1, (int) config('ai-assistant.rag.failed_retry_after_hours', 12)))
            : null;

        foreach ($organizations as $organization) {
            foreach ($this->projectIdsForOrganization($organization->id) as $projectId) {
                if (
                    $staleOnly
                    && (
                        $this->hasActiveRun($organization->id, $projectId, $sourceType)
                        || (
                            $freshCutoff instanceof Carbon
                            && $this->hasFreshSucceededRun($organization->id, $projectId, $sourceType, $freshCutoff)
                        )
                        || (
                            $failedCutoff instanceof Carbon
                            && $this->hasRecentFailedRun($organization->id, $projectId, $sourceType, $failedCutoff)
                        )
                    )
                ) {
                    continue;
                }

                $run = $this->queueOrganization($organization->id, $projectId, $sourceType, $mode);
                $runIds[] = $run->id;
                $organizationIds[] = $organization->id;
                $projectIds[] = $projectId;
            }
        }

        return [
            'queued' => count($runIds),
            'run_ids' => $runIds,
            'organization_ids' => $organizationIds,
            'project_ids' => $projectIds,
        ];
    }

    /**
     * @return array{processed: int, run_ids: array<int, int>, organization_ids: array<int, int>}
     */
    public function indexAllActiveOrganizationsSync(
        bool $includeInactive = false,
        ?int $limit = null,
        ?int $projectId = null,
        ?string $sourceType = null,
        bool $staleOnly = false,
        ?int $staleAfterHours = null
    ): array {
        $organizations = $this->organizationsForBulkIndex(
            $includeInactive,
            $limit,
            $staleOnly,
            $staleAfterHours,
            $projectId,
            $sourceType
        );
        $runIds = [];
        $organizationIds = [];

        foreach ($organizations as $organization) {
            $run = $this->indexOrganizationSync($organization->id, $projectId, $sourceType);
            $runIds[] = $run->id;
            $organizationIds[] = $organization->id;
        }

        return [
            'processed' => count($runIds),
            'run_ids' => $runIds,
            'organization_ids' => $organizationIds,
        ];
    }

    public function queueOrganization(
        int $organizationId,
        ?int $projectId = null,
        ?string $sourceType = null,
        string $mode = RagIndexRun::MODE_ASYNC
    ): RagIndexRun {
        $run = RagIndexRun::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'source_type' => $sourceType,
            'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => $mode,
            'queued_at' => now(),
            'last_error' => RagDispatchIntent::pending(),
        ]);

        $this->invalidateCoverageAfterCommit($organizationId);
        $this->dispatchRunAfterCommit($run);

        return $run;
    }

    public function recoverExpiredRuns(): int
    {
        $cutoff = now();
        $queueName = (string) config('ai-assistant.rag.queue', 'ai-rag');
        $liveQueueName = (string) config('ai-assistant.rag.live_queue', 'ai-rag-live');
        $recoverBulkQueued = RagQueueBacklog::isQueueEmpty($queueName);
        $recoverLiveQueued = $liveQueueName === $queueName
            ? $recoverBulkQueued
            : RagQueueBacklog::isQueueEmpty($liveQueueName);
        $runs = RagIndexRun::query()
            ->whereIn('status', [RagIndexRun::STATUS_QUEUED, RagIndexRun::STATUS_RUNNING])
            ->where(function (Builder $query) use ($cutoff, $recoverBulkQueued, $recoverLiveQueued): void {
                $this->applyRecoveryEligibility($query, $cutoff, $recoverBulkQueued, $recoverLiveQueued);
            })
            ->orderBy('id')
            ->limit(25)
            ->get();
        $recovered = 0;

        foreach ($runs as $run) {
            $updated = RagIndexRun::query()->whereKey($run->id)->where('updated_at', $run->updated_at)->where('status', $run->status)
                ->where('last_error', $run->last_error)->where('lease_token', $run->lease_token)
                ->where(function (Builder $query) use ($cutoff, $recoverBulkQueued, $recoverLiveQueued): void {
                    $this->applyRecoveryEligibility($query, $cutoff, $recoverBulkQueued, $recoverLiveQueued);
                })->update([
                    'status' => RagIndexRun::STATUS_QUEUED,
                    'queued_at' => now(),
                    'started_at' => null,
                    'heartbeat_at' => null,
                    'lease_expires_at' => null,
                    'lease_token' => null,
                    'last_error' => RagDispatchIntent::pending(),
                    'updated_at' => now(),
                ]);

            if ($updated === 1) {
                $recovered++;
                $this->dispatchRunAfterCommit($run->refresh());
            }
        }

        return $recovered;
    }

    public function queueEntity(int $organizationId, ?int $projectId, string $sourceType, string $entityType, string|int $entityId): RagIndexRun
    {
        return $this->queueEntityRun($organizationId, $projectId, $sourceType, $entityType, $entityId, RagIndexRun::MODE_ASYNC);
    }

    public function queueEntities(int $organizationId, ?int $projectId, string $sourceType, string $entityType, array $entityIds): void
    {
        if ($entityIds === []) {
            return;
        }
        if (count($entityIds) > 500) {
            throw new \InvalidArgumentException('RAG entity batch exceeds 500 items.');
        }
        $entityIds = array_values(array_unique(array_map(static fn (string|int $id): string => (string) $id, $entityIds)));

        DB::transaction(function () use ($organizationId, $projectId, $sourceType, $entityType, $entityIds): void {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail(['id']);
            $pending = RagIndexRun::query()->where('organization_id', $organizationId)->where('source_type', $sourceType)
                ->where('entity_type', $entityType)->whereIn('entity_id', $entityIds)
                ->where('status', RagIndexRun::STATUS_QUEUED)->get(['id', 'entity_id', 'mode']);
            $newIds = array_diff($entityIds, $pending->pluck('entity_id')->all());
            $scheduledIds = $pending->where('mode', RagIndexRun::MODE_SCHEDULED)->pluck('id')->all();
            $marker = RagDispatchIntent::pending();
            $timestamp = now();
            if ($newIds !== []) {
                RagIndexRun::query()->insert(array_map(static fn (string $id): array => [
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                    'source_type' => $sourceType,
                    'entity_type' => $entityType,
                    'entity_id' => $id,
                    'status' => RagIndexRun::STATUS_QUEUED,
                    'mode' => RagIndexRun::MODE_ASYNC,
                    'queued_at' => $timestamp,
                    'last_error' => $marker,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], array_values($newIds)));
            }
            if ($scheduledIds !== []) {
                RagIndexRun::query()->whereIn('id', $scheduledIds)->update([
                    'mode' => RagIndexRun::MODE_ASYNC,
                    'last_error' => $marker,
                    'queued_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
            $this->invalidateCoverageAfterCommit($organizationId);
            if ($newIds !== [] || $scheduledIds !== []) {
                $this->dispatchEntityBatchAfterCommit($organizationId, $marker);
            }
        });
    }

    public function queueFileRegistration(int $organizationId, int $fileId, bool $archive = false): RagIndexRun
    {
        return $this->queueEntityRun($organizationId, null, 'file_document', 'file', $fileId,
            $archive ? RagIndexRun::MODE_SCHEDULED : RagIndexRun::MODE_ASYNC);
    }

    private function queueEntityRun(int $organizationId, ?int $projectId, string $sourceType, string $entityType, string|int $entityId, string $mode): RagIndexRun
    {
        return DB::transaction(function () use ($organizationId, $projectId, $sourceType, $entityType, $entityId, $mode): RagIndexRun {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail(['id']);
            $this->invalidateCoverageAfterCommit($organizationId);
            $pending = RagIndexRun::query()->where('organization_id', $organizationId)->where('source_type', $sourceType)
                ->where('entity_type', $entityType)->where('entity_id', (string) $entityId)->where('status', RagIndexRun::STATUS_QUEUED)->first();
            if ($pending instanceof RagIndexRun) {
                if ($mode === RagIndexRun::MODE_ASYNC && $pending->mode === RagIndexRun::MODE_SCHEDULED) {
                    $pending->update(['mode' => RagIndexRun::MODE_ASYNC, 'last_error' => RagDispatchIntent::pending(), 'queued_at' => now()]);
                    $this->dispatchRunAfterCommit($pending);
                }
                return $pending;
            }
            $run = RagIndexRun::query()->create([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'source_type' => $sourceType,
                'entity_type' => $entityType,
                'entity_id' => (string) $entityId,
                'status' => RagIndexRun::STATUS_QUEUED,
                'mode' => $mode,
                'queued_at' => now(),
                'last_error' => RagDispatchIntent::pending(),
            ]);
            $this->dispatchRunAfterCommit($run);
            return $run;
        });
    }
    public function heartbeat(int $runId, ?string $leaseToken = null, ?int $processed = null): bool
    {
        return RagIndexRun::query()
            ->whereKey($runId)
            ->where('status', RagIndexRun::STATUS_RUNNING)
            ->where('lease_expires_at', '>', now())
            ->when($leaseToken !== null, static fn (Builder $query): Builder => $query->where('lease_token', $leaseToken))
            ->update([
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addMinutes($this->leaseMinutes()),
                'updated_at' => now(),
                ...($processed === null ? [] : ['processed_sources' => $processed]),
            ]) === 1;
    }

    public function releaseForRetry(int $runId, string $leaseToken, Throwable $exception): void
    {
        RagIndexRun::query()->whereKey($runId)->where('lease_token', $leaseToken)->where('status', RagIndexRun::STATUS_RUNNING)
            ->update(['status' => RagIndexRun::STATUS_QUEUED, 'lease_expires_at' => null, 'last_error' => $exception::class, 'updated_at' => now()]);
    }

    public function shouldSplitOrganizationSourceByProjects(string $sourceType): bool
    {
        $sourceTypes = config('ai-assistant.rag.scheduled_project_scoped_source_types', ['estimate']);
        if (! is_array($sourceTypes)) {
            return false;
        }

        return in_array($sourceType, $sourceTypes, true);
    }

    public function splitOrganizationSourceRunByProjects(
        int $runId,
        int $organizationId,
        string $sourceType
    ): int {
        $queued = 0;
        $freshCutoff = now()->subHours(max(1, (int) config('ai-assistant.rag.stale_after_hours', 24)));
        $failedCutoff = now()->subHours(max(1, (int) config('ai-assistant.rag.failed_retry_after_hours', 12)));

        foreach ($this->projectIdsForOrganization($organizationId) as $projectId) {
            if (
                $this->hasExactActiveRun($organizationId, $projectId, $sourceType)
                || $this->hasFreshSucceededRun($organizationId, $projectId, $sourceType, $freshCutoff)
                || $this->hasRecentFailedRun($organizationId, $projectId, $sourceType, $failedCutoff)
            ) {
                continue;
            }

            $this->queueOrganization($organizationId, $projectId, $sourceType, RagIndexRun::MODE_SCHEDULED);
            $queued++;
        }

        $this->markSucceeded($runId, 0);

        return $queued;
    }

    public function splitScheduledOrganizationSourceRunByProjectsIfNeeded(int $runId): bool
    {
        $run = $this->findRun($runId);
        if (! $run instanceof RagIndexRun) {
            return false;
        }

        if (
            $run->mode !== RagIndexRun::MODE_SCHEDULED
            || $run->project_id !== null
            || ! is_string($run->source_type)
            || ! $this->shouldSplitOrganizationSourceByProjects($run->source_type)
        ) {
            return false;
        }

        $this->splitOrganizationSourceRunByProjects($run->id, $run->organization_id, $run->source_type);

        return true;
    }

    public function splitProjectEstimateRunByEstimates(int $runId, int $organizationId, int $projectId): int
    {
        $queued = 0;

        foreach ($this->estimateIdsForProject($organizationId, $projectId) as $estimateId) {
            $this->queueEntity($organizationId, $projectId, 'estimate', 'estimate', $estimateId);
            $queued++;
        }

        if ($queued > 0) {
            $this->markSucceeded($runId, 0);
        }

        return $queued;
    }

    public function indexOrganizationSync(
        int $organizationId,
        ?int $projectId = null,
        ?string $sourceType = null
    ): RagIndexRun {
        $run = RagIndexRun::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'source_type' => $sourceType,
            'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => RagIndexRun::MODE_SYNC,
            'queued_at' => now(),
        ]);
        $this->invalidateCoverageAfterCommit($organizationId);
        $run = $this->markRunning($run->id) ?? $run;
        $leaseToken = $run->lease_token;
        $progress = function (int $processed) use ($run, $leaseToken): void {
            if (! $this->heartbeat($run->id, $leaseToken, $processed)) {
                throw new \RuntimeException('RAG indexing lease lost');
            }
        };

        try {
            $indexed = $this->indexer->indexOrganization($organizationId, $projectId, $sourceType, $progress);

            return $this->markSucceeded($run->id, $indexed, $leaseToken) ?? $run->refresh();
        } catch (Throwable $throwable) {
            $this->markFailed($run->id, $throwable, $leaseToken);

            throw $throwable;
        }
    }

    public function markRunning(int $runId): ?RagIndexRun
    {
        $run = $this->findRun($runId);
        if (! $run instanceof RagIndexRun) {
            return null;
        }

        if (in_array($run->status, [RagIndexRun::STATUS_SUCCEEDED, RagIndexRun::STATUS_FAILED], true)) {
            Log::warning('ai_assistant.rag.index_run_already_finished', [
                'run_id' => $runId,
                'status' => $run->status,
            ]);

            return null;
        }

        $claimed = RagIndexRun::query()
            ->whereKey($run->id)
            ->where(static function (Builder $query): void {
                $query->where('status', RagIndexRun::STATUS_QUEUED)
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', RagIndexRun::STATUS_RUNNING)
                            ->where('lease_expires_at', '<=', now());
                    });
            })
            ->update([
            'status' => RagIndexRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addMinutes($this->leaseMinutes()),
            'lease_token' => (string) Str::uuid(),
            'last_error' => null,
            'updated_at' => now(),
        ]);
        if ($claimed !== 1) {
            return null;
        }

        return $run->refresh();
    }

    public function markSucceeded(int $runId, int $indexedChunks, ?string $leaseToken = null): ?RagIndexRun
    {
        $run = $this->findRun($runId);
        if (! $run instanceof RagIndexRun) {
            return null;
        }

        if ($leaseToken !== null && $run->lease_token !== $leaseToken) {
            return null;
        }

        $finishedAt = now();
        $startedAt = $run->started_at instanceof Carbon ? $run->started_at : $finishedAt;
        $counts = $this->countsForScope($run->organization_id, $run->project_id, $run->source_type);

        $attributes = [
            'status' => RagIndexRun::STATUS_SUCCEEDED,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'indexed_chunks' => $indexedChunks,
            'source_count' => $counts['source_count'],
            'chunk_count' => $counts['chunk_count'],
            'processed_sources' => $indexedChunks,
            'expected_sources' => $indexedChunks,
            'scan_completed_at' => $run->entity_type === null ? $finishedAt : null,
            'heartbeat_at' => $finishedAt,
            'lease_expires_at' => null,
            'last_error' => null,
        ];
        $updated = RagIndexRun::query()->whereKey($runId)
            ->when($leaseToken !== null, static fn (Builder $query): Builder => $query->where('lease_token', $leaseToken))
            ->update($attributes);
        if ($updated !== 1) {
            return null;
        }

        $this->invalidateCoverageAfterCommit($run->organization_id);
        return $run->refresh();
    }

    public function markFailed(int $runId, Throwable $throwable, ?string $leaseToken = null): ?RagIndexRun
    {
        $run = $this->findRun($runId);
        if (! $run instanceof RagIndexRun) {
            return null;
        }

        if ($leaseToken !== null && $run->lease_token !== $leaseToken) {
            return null;
        }

        $finishedAt = now();
        $startedAt = $run->started_at instanceof Carbon ? $run->started_at : $finishedAt;

        $attributes = [
            'status' => RagIndexRun::STATUS_FAILED,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'last_error' => Str::limit($throwable->getMessage(), 2000, ''),
            'lease_expires_at' => null,
        ];
        $updated = RagIndexRun::query()->whereKey($runId)
            ->when($leaseToken !== null, static fn (Builder $query): Builder => $query->where('lease_token', $leaseToken))
            ->update($attributes);
        if ($updated !== 1) {
            return null;
        }

        $this->invalidateCoverageAfterCommit($run->organization_id);
        return $run->refresh();
    }

    /**
     * @return array{source_count: int, chunk_count: int}
     */
    public function countsForScope(int $organizationId, ?int $projectId = null, ?string $sourceType = null): array
    {
        $sources = RagSource::query()
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn (Builder $query): Builder => $query->where('project_id', $projectId))
            ->when($sourceType !== null, static fn (Builder $query): Builder => $query->where('source_type', $sourceType));

        $chunks = RagChunk::query()
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn (Builder $query): Builder => $query->where('project_id', $projectId))
            ->when($sourceType !== null, static fn (Builder $query): Builder => $query->whereHas(
                'source',
                static fn (Builder $sourceQuery): Builder => $sourceQuery->where('source_type', $sourceType)
            ));

        return [
            'source_count' => (int) $sources->count(),
            'chunk_count' => (int) $chunks->count(),
        ];
    }

    private function findRun(int $runId): ?RagIndexRun
    {
        $run = RagIndexRun::query()->find($runId);

        if (! $run instanceof RagIndexRun) {
            Log::warning('ai_assistant.rag.index_run_missing', [
                'run_id' => $runId,
            ]);

            return null;
        }

        return $run;
    }

    private function leaseMinutes(): int
    {
        return max(1, (int) config('ai-assistant.rag.lease_minutes', 15));
    }

    private function applyRecoveryEligibility(Builder $query, Carbon $cutoff, bool $recoverBulkQueued, bool $recoverLiveQueued): void
    {
        $retryMinutes = max(1, min(4, (int) config('ai-assistant.rag.queued_retry_minutes', 2)));
        $query->where(function (Builder $states) use ($cutoff, $retryMinutes, $recoverBulkQueued, $recoverLiveQueued): void {
            $retryCutoff = $cutoff->copy()->subMinutes($retryMinutes);
            $states->where(function (Builder $queued) use ($retryCutoff, $recoverBulkQueued, $recoverLiveQueued): void {
                $queued->where('status', RagIndexRun::STATUS_QUEUED)
                    ->where(function (Builder $targetQueue) use ($recoverBulkQueued, $recoverLiveQueued): void {
                        $targetQueue->where(function (Builder $bulkQueue) use ($recoverBulkQueued): void {
                            $bulkQueue->where(function (Builder $target): void {
                                $target->whereNull('entity_type')->orWhere(function (Builder $scheduled): void {
                                    $scheduled->where('source_type', 'file_document')
                                        ->where('entity_type', 'file')
                                        ->where('mode', RagIndexRun::MODE_SCHEDULED);
                                });
                            });
                            if (! $recoverBulkQueued) {
                                RagDispatchIntent::scopePending($bulkQueue);
                            }
                        })->orWhere(function (Builder $liveQueue) use ($recoverLiveQueued): void {
                            $liveQueue->whereNotNull('entity_type')
                                ->where(function (Builder $notScheduled): void {
                                    $notScheduled->whereNull('source_type')
                                        ->orWhere('source_type', '!=', 'file_document')
                                        ->orWhere('entity_type', '!=', 'file')
                                        ->orWhereNull('mode')
                                        ->orWhere('mode', '!=', RagIndexRun::MODE_SCHEDULED);
                                });
                            if (! $recoverLiveQueued) {
                                RagDispatchIntent::scopePending($liveQueue);
                            }
                        });
                    })
                    ->where(function (Builder $activity) use ($retryCutoff): void {
                        $activity->where('queued_at', '<=', $retryCutoff)->orWhere(function (Builder $legacy) use ($retryCutoff): void {
                            $legacy->whereNull('queued_at');
                            $this->applyLegacyActivityCutoff($legacy, $retryCutoff);
                        });
                    });
            })->orWhere(function (Builder $running) use ($cutoff): void {
                $running->where('status', RagIndexRun::STATUS_RUNNING)->where(function (Builder $lease) use ($cutoff): void {
                    $lease->where('lease_expires_at', '<=', $cutoff)->orWhere(function (Builder $legacy) use ($cutoff): void {
                        $legacy->whereNull('lease_expires_at');
                        $this->applyLegacyActivityCutoff($legacy, $cutoff->copy()->subMinutes($this->leaseMinutes()));
                    });
                });
            });
        });
    }

    private function applyLegacyActivityCutoff(Builder $query, Carbon $cutoff): void
    {
        $timestamps = ['heartbeat_at', 'started_at', 'updated_at', 'created_at', 'queued_at'];
        $query->where(static function (Builder $known) use ($timestamps): void {
            foreach ($timestamps as $timestamp) {
                $known->orWhereNotNull($timestamp);
            }
        });
        foreach ($timestamps as $timestamp) {
            $query->where(static fn (Builder $activity): Builder => $activity->whereNull($timestamp)->orWhere($timestamp, '<=', $cutoff));
        }
    }

    private function invalidateCoverageAfterCommit(int $organizationId): void
    {
        DB::afterCommit(static function () use ($organizationId): void {
            try {
                RagCoverageService::invalidate($organizationId);
            } catch (Throwable $exception) {
                try {
                    Log::warning('ai_assistant.rag.coverage_invalidation_failed', ['organization_id' => $organizationId, 'exception_class' => $exception::class]);
                } catch (Throwable) {
                }
            }
        });
    }

    private function dispatchRunAfterCommit(RagIndexRun $run): void
    {
        $runId = $run->id;
        $marker = $run->last_error;
        DB::afterCommit(function () use ($runId, $marker): void {
            try {
                $current = RagIndexRun::query()->whereKey($runId)->where('last_error', $marker)
                    ->where('status', RagIndexRun::STATUS_QUEUED)->first();
                if (! $current instanceof RagIndexRun || ! RagDispatchIntent::isPending($marker)) {
                    return;
                }
                $dispatcher = $this->dispatcher ?? app(RagJobDispatcher::class);
                $job = new IndexRagSourceJob($current->organization_id, $current->project_id, $current->source_type,
                    $current->id, $current->entity_type, $current->entity_id);
                if ($current->source_type === 'file_document' && $current->entity_type === 'file' && $current->mode === RagIndexRun::MODE_SCHEDULED) {
                    $job->onQueue((string) config('ai-assistant.rag.queue', 'ai-rag'));
                }
                $accepted = $dispatcher->dispatch($job, static function (Throwable $exception) use ($runId, $marker): void {
                    RagIndexRun::query()->whereKey($runId)->where('status', RagIndexRun::STATUS_QUEUED)->where('last_error', $marker)
                        ->update(['last_error' => RagDispatchIntent::failed((string) $marker, $exception), 'updated_at' => now()]);
                });
                if ($accepted) {
                    RagIndexRun::query()->whereKey($runId)->where('status', RagIndexRun::STATUS_QUEUED)->where('last_error', $marker)
                        ->update(['last_error' => null, 'updated_at' => now()]);
                }
            } catch (Throwable $exception) {
                try {
                    Log::warning('ai_assistant.rag.dispatch_setup_failed', ['run_id' => $runId, 'exception_class' => $exception::class]);
                } catch (Throwable) {
                }
            }
        });
    }

    private function dispatchEntityBatchAfterCommit(int $organizationId, string $marker): void
    {
        DB::afterCommit(function () use ($organizationId, $marker): void {
            try {
                $runs = RagIndexRun::query()->where('organization_id', $organizationId)
                    ->where('status', RagIndexRun::STATUS_QUEUED)->where('last_error', $marker)->get();
                if ($runs->isEmpty()) {
                    return;
                }
                $runIds = $runs->pluck('id')->all();
                $jobs = $runs->map(static fn (RagIndexRun $run): IndexRagSourceJob => new IndexRagSourceJob(
                    $run->organization_id, $run->project_id, $run->source_type, $run->id, $run->entity_type, $run->entity_id,
                ))->all();
                $dispatcher = $this->dispatcher ?? app(RagJobDispatcher::class);
                $accepted = $dispatcher->dispatchMany($jobs, static function (Throwable $exception) use ($runIds, $marker): void {
                    RagIndexRun::query()->whereIn('id', $runIds)->where('status', RagIndexRun::STATUS_QUEUED)
                        ->where('last_error', $marker)->update([
                            'last_error' => RagDispatchIntent::failed($marker, $exception),
                            'updated_at' => now(),
                        ]);
                });
                if ($accepted) {
                    RagIndexRun::query()->whereIn('id', $runIds)->where('status', RagIndexRun::STATUS_QUEUED)
                        ->where('last_error', $marker)->update(['last_error' => null, 'updated_at' => now()]);
                }
            } catch (Throwable $exception) {
                try {
                    Log::warning('ai_assistant.rag.batch_dispatch_setup_failed', [
                        'organization_id' => $organizationId,
                        'exception_class' => $exception::class,
                    ]);
                } catch (Throwable) {
                }
            }
        });
    }

    /**
     * @return Collection<int, Organization>
     */
    private function organizationsForBulkIndex(
        bool $includeInactive,
        ?int $limit,
        bool $staleOnly = false,
        ?int $staleAfterHours = null,
        ?int $projectId = null,
        ?string $sourceType = null
    ): Collection {
        $query = Organization::query()
            ->select(['id'])
            ->when(! $includeInactive, static fn (Builder $query): Builder => $query->where('is_active', true));

        $packageCatalog = app(PackageCatalogService::class);
        $packageSlugs = array_column($packageCatalog->allPackages(), 'slug');
        $packageSlugs = array_merge($packageSlugs, $packageCatalog->retiredEntryPackageSlugs());
        $eligiblePackageSlugs = [];

        foreach ($packageSlugs as $packageSlug) {
            if (! is_string($packageSlug)) {
                continue;
            }

            if (in_array(self::ASSISTANT_MODULE_SLUG, $packageCatalog->tierModules($packageSlug, 'standard'), true)) {
                $eligiblePackageSlugs[] = $packageSlug;
            }
        }

        if ($eligiblePackageSlugs === []) {
            return collect();
        }

        $organizationTable = (new Organization)->getTable();
        $moduleTable = (new Module)->getTable();
        $packageSubscriptions = OrganizationPackageSubscription::query()
            ->select('organization_id')
            ->whereIn('package_slug', array_values(array_unique($eligiblePackageSlugs)))
            ->whereHas('commercialAccount', static function (Builder $account): void {
                $account->whereColumn(
                    'organization_commercial_accounts.organization_id',
                    'organization_package_subscriptions.organization_id',
                );
            })
            ->active();

        $query
            ->whereIn("{$organizationTable}.id", $packageSubscriptions)
            ->whereExists(static function (QueryBuilder $moduleQuery) use ($moduleTable): void {
                $moduleQuery
                    ->selectRaw('1')
                    ->from($moduleTable)
                    ->where("{$moduleTable}.slug", self::ASSISTANT_MODULE_SLUG)
                    ->where("{$moduleTable}.is_active", true);
            });

        if ($staleOnly) {
            $freshnessWindow = max(1, $staleAfterHours ?? (int) config('ai-assistant.rag.stale_after_hours', 24));
            $cutoff = now()->subHours($freshnessWindow);
            $failedRetryAfterHours = max(1, (int) config('ai-assistant.rag.failed_retry_after_hours', 12));
            $failedCutoff = now()->subHours($failedRetryAfterHours);
            $this->applyStaleOnlyConstraint($query, $cutoff, $failedCutoff, $projectId, $sourceType);
            $this->orderByOldestRagAttempt($query);
        } else {
            $query->orderBy('id');
        }

        return $query
            ->when($limit !== null && $limit > 0, static fn (Builder $query): Builder => $query->limit($limit))
            ->get();
    }

    private function applyStaleOnlyConstraint(
        Builder $query,
        Carbon $cutoff,
        Carbon $failedCutoff,
        ?int $projectId,
        ?string $sourceType
    ): void {
        $organizationTable = (new Organization)->getTable();
        $runTable = (new RagIndexRun)->getTable();

        $query
            ->whereNotExists(function (QueryBuilder $subQuery) use (
                $organizationTable,
                $projectId,
                $runTable,
                $sourceType
            ): void {
                $subQuery
                    ->selectRaw('1')
                    ->from($runTable)
                    ->whereColumn("{$runTable}.organization_id", "{$organizationTable}.id")
                    ->whereIn("{$runTable}.status", [
                        RagIndexRun::STATUS_QUEUED,
                        RagIndexRun::STATUS_RUNNING,
                    ]);

                $this->applyActiveRunScope($subQuery, $runTable, $projectId, $sourceType);
            })
            ->whereNotExists(
                function (QueryBuilder $subQuery) use (
                    $failedCutoff,
                    $organizationTable,
                    $projectId,
                    $runTable,
                    $sourceType
                ): void {
                    $subQuery
                        ->selectRaw('1')
                        ->from($runTable)
                        ->whereColumn("{$runTable}.organization_id", "{$organizationTable}.id")
                        ->where("{$runTable}.status", RagIndexRun::STATUS_FAILED)
                        ->where("{$runTable}.queued_at", '>', $failedCutoff->toDateTimeString());

                    $this->applyRunScope($subQuery, $runTable, $projectId, $sourceType);
                }
            )
            ->whereNotExists(
                function (QueryBuilder $subQuery) use (
                    $cutoff,
                    $organizationTable,
                    $projectId,
                    $runTable,
                    $sourceType
                ): void {
                    $subQuery
                        ->selectRaw('1')
                        ->from($runTable)
                        ->whereColumn("{$runTable}.organization_id", "{$organizationTable}.id")
                        ->where("{$runTable}.status", RagIndexRun::STATUS_SUCCEEDED)
                        ->where("{$runTable}.finished_at", '>', $cutoff);

                    $this->applyRunScope($subQuery, $runTable, $projectId, $sourceType);
                }
            );
    }

    private function applyRunScope(
        QueryBuilder $query,
        string $runTable,
        ?int $projectId,
        ?string $sourceType
    ): void {
        $query->whereNull("{$runTable}.entity_type");
        if ($projectId === null) {
            $query->whereNull("{$runTable}.project_id");
        } else {
            $query->where("{$runTable}.project_id", $projectId);
        }

        if ($sourceType === null) {
            $query->whereNull("{$runTable}.source_type");
        } else {
            $query->where("{$runTable}.source_type", $sourceType);
        }
    }

    private function hasRecentFailedRun(
        int $organizationId,
        ?int $projectId,
        ?string $sourceType,
        Carbon $failedCutoff
    ): bool {
        return RagIndexRun::query()
            ->where('organization_id', $organizationId)
            ->whereNull('entity_type')
            ->where('status', RagIndexRun::STATUS_FAILED)
            ->where('queued_at', '>', $failedCutoff->toDateTimeString())
            ->when(
                $projectId === null,
                static fn (Builder $query): Builder => $query->whereNull('project_id'),
                static fn (Builder $query): Builder => $query->where('project_id', $projectId)
            )
            ->when(
                $sourceType === null,
                static fn (Builder $query): Builder => $query->whereNull('source_type'),
                static fn (Builder $query): Builder => $query->where('source_type', $sourceType)
            )
            ->exists();
    }

    private function hasActiveRun(int $organizationId, ?int $projectId, ?string $sourceType): bool
    {
        $query = RagIndexRun::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', [
                RagIndexRun::STATUS_QUEUED,
                RagIndexRun::STATUS_RUNNING,
            ]);

        $this->applyActiveEloquentRunScope($query, $projectId, $sourceType);

        return $query->exists();
    }

    private function hasFreshSucceededRun(
        int $organizationId,
        ?int $projectId,
        ?string $sourceType,
        Carbon $freshCutoff
    ): bool {
        $query = RagIndexRun::query()
            ->where('organization_id', $organizationId)
            ->where('status', RagIndexRun::STATUS_SUCCEEDED)
            ->where('finished_at', '>', $freshCutoff->toDateTimeString());

        $this->applyActiveEloquentRunScope($query, $projectId, $sourceType);

        return $query->exists();
    }

    private function hasExactActiveRun(int $organizationId, ?int $projectId, ?string $sourceType): bool
    {
        return RagIndexRun::query()
            ->where('organization_id', $organizationId)
            ->whereNull('entity_type')
            ->whereIn('status', [
                RagIndexRun::STATUS_QUEUED,
                RagIndexRun::STATUS_RUNNING,
            ])
            ->when(
                $projectId === null,
                static fn (Builder $query): Builder => $query->whereNull('project_id'),
                static fn (Builder $query): Builder => $query->where('project_id', $projectId)
            )
            ->when(
                $sourceType === null,
                static fn (Builder $query): Builder => $query->whereNull('source_type'),
                static fn (Builder $query): Builder => $query->where('source_type', $sourceType)
            )
            ->exists();
    }

    /**
     * @return array<int, int>
     */
    private function projectIdsForOrganization(int $organizationId): array
    {
        return Project::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (int|string $projectId): int => (int) $projectId)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function estimateIdsForProject(int $organizationId, int $projectId): array
    {
        return Estimate::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (int|string $estimateId): int => (int) $estimateId)
            ->all();
    }

    private function applyActiveRunScope(
        QueryBuilder $query,
        string $runTable,
        ?int $projectId,
        ?string $sourceType
    ): void {
        $query->whereNull("{$runTable}.entity_type");
        if ($projectId === null) {
            $query->whereNull("{$runTable}.project_id");
        } else {
            $query->where(function (QueryBuilder $scopeQuery) use ($projectId, $runTable): void {
                $scopeQuery
                    ->where("{$runTable}.project_id", $projectId)
                    ->orWhereNull("{$runTable}.project_id");
            });
        }

        if ($sourceType === null) {
            $query->whereNull("{$runTable}.source_type");
        } else {
            $query->where(function (QueryBuilder $scopeQuery) use ($runTable, $sourceType): void {
                $scopeQuery
                    ->where("{$runTable}.source_type", $sourceType)
                    ->orWhereNull("{$runTable}.source_type");
            });
        }
    }

    private function applyActiveEloquentRunScope(Builder $query, ?int $projectId, ?string $sourceType): void
    {
        $query->whereNull('entity_type');
        if ($projectId === null) {
            $query->whereNull('project_id');
        } else {
            $query->where(static function (Builder $scopeQuery) use ($projectId): void {
                $scopeQuery
                    ->where('project_id', $projectId)
                    ->orWhereNull('project_id');
            });
        }

        if ($sourceType === null) {
            $query->whereNull('source_type');
        } else {
            $query->where(static function (Builder $scopeQuery) use ($sourceType): void {
                $scopeQuery
                    ->where('source_type', $sourceType)
                    ->orWhereNull('source_type');
            });
        }
    }

    private function orderByOldestRagAttempt(Builder $query): void
    {
        $organizationTable = (new Organization)->getTable();
        $runTable = (new RagIndexRun)->getTable();
        $latestAttemptSql = sprintf(
            '(select max(%s.created_at) from %s where %s.organization_id = %s.id)',
            $runTable,
            $runTable,
            $runTable,
            $organizationTable
        );

        $query
            ->orderByRaw("{$latestAttemptSql} is not null")
            ->orderByRaw("{$latestAttemptSql} asc")
            ->orderBy("{$organizationTable}.id");
    }
}
