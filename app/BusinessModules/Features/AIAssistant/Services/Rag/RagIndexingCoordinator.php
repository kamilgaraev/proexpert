<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RagIndexingCoordinator
{
    public function __construct(
        private readonly RagIndexer $indexer
    ) {
    }

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
        ]);

        dispatch(new IndexRagSourceJob($organizationId, $projectId, $sourceType, $run->id));

        return $run;
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
            'status' => RagIndexRun::STATUS_RUNNING,
            'mode' => RagIndexRun::MODE_SYNC,
            'queued_at' => now(),
            'started_at' => now(),
        ]);

        try {
            $indexed = $this->indexer->indexOrganization($organizationId, $projectId, $sourceType);

            return $this->markSucceeded($run->id, $indexed) ?? $run->refresh();
        } catch (Throwable $throwable) {
            $this->markFailed($run->id, $throwable);

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

        $run->forceFill([
            'status' => RagIndexRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'last_error' => null,
        ])->save();

        return $run;
    }

    public function markSucceeded(int $runId, int $indexedChunks): ?RagIndexRun
    {
        $run = $this->findRun($runId);
        if (! $run instanceof RagIndexRun) {
            return null;
        }

        $finishedAt = now();
        $startedAt = $run->started_at instanceof Carbon ? $run->started_at : $finishedAt;
        $counts = $this->countsForScope($run->organization_id, $run->project_id, $run->source_type);

        $run->forceFill([
            'status' => RagIndexRun::STATUS_SUCCEEDED,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'indexed_chunks' => $indexedChunks,
            'source_count' => $counts['source_count'],
            'chunk_count' => $counts['chunk_count'],
            'last_error' => null,
        ])->save();

        return $run;
    }

    public function markFailed(int $runId, Throwable $throwable): ?RagIndexRun
    {
        $run = $this->findRun($runId);
        if (! $run instanceof RagIndexRun) {
            return null;
        }

        $finishedAt = now();
        $startedAt = $run->started_at instanceof Carbon ? $run->started_at : $finishedAt;

        $run->forceFill([
            'status' => RagIndexRun::STATUS_FAILED,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'last_error' => Str::limit($throwable->getMessage(), 2000, ''),
        ])->save();

        return $run;
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
    ): Collection
    {
        $query = Organization::query()
            ->select(['id'])
            ->when(! $includeInactive, static fn (Builder $query): Builder => $query->where('is_active', true));

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
        $organizationTable = (new Organization())->getTable();
        $runTable = (new RagIndexRun())->getTable();

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

    private function applyActiveRunScope(
        QueryBuilder $query,
        string $runTable,
        ?int $projectId,
        ?string $sourceType
    ): void {
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

    private function orderByOldestRagAttempt(Builder $query): void
    {
        $organizationTable = (new Organization())->getTable();
        $runTable = (new RagIndexRun())->getTable();
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
