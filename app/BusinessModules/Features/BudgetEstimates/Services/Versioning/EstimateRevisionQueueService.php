<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\Jobs\CreateEstimateRevision;
use App\Models\Estimate;
use App\Models\EstimateVersion;
use App\Models\EstimateRevisionOperation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

final class EstimateRevisionQueueService
{
    public function __construct(private readonly EstimateRevisionService $revisions) {}

    public function enqueue(int $estimateId, int $organizationId, User $actor, string $reason, string $key, ?int $targetVersionId = null): EstimateRevisionOperation
    {
        $estimate = Estimate::query()->whereKey($estimateId)->where('organization_id', $organizationId)->firstOrFail();
        Gate::forUser($actor)->authorize('view', $estimate);
        if ($targetVersionId !== null) {
            EstimateVersion::query()->where('estimate_id', $estimateId)->where('organization_id', $organizationId)->findOrFail($targetVersionId, ['id']);
        }
        $existing = EstimateRevisionOperation::query()->where('estimate_id', $estimateId)
            ->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            $this->assertSameTarget($existing, $targetVersionId);
            return $existing;
        }
        $this->authorize($estimateId, $organizationId, $actor, $targetVersionId !== null);

        $operation = DB::transaction(function () use ($estimate, $organizationId, $actor, $reason, $key, $targetVersionId): EstimateRevisionOperation {
            $locked = Estimate::query()->whereKey($estimate->id)->lock('FOR UPDATE NOWAIT')->firstOrFail();
            $existing = EstimateRevisionOperation::query()->where('estimate_id', $locked->id)
                ->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $this->assertSameTarget($existing, $targetVersionId);
                return $existing;
            }
            if (EstimateRevisionOperation::query()->where('estimate_id', $locked->id)
                ->whereIn('status', ['queued', 'processing'])->exists()) {
                throw new DomainException(trans_message('estimate.revision_already_pending'));
            }
            if ($targetVersionId === null && ($locked->status !== 'approved' || $locked->current_version_id === null)) {
                throw new DomainException(trans_message('estimate.revision_requires_approved_estimate'));
            }

            return EstimateRevisionOperation::query()->create([
                'id' => (string) Str::uuid(),
                'estimate_id' => $locked->id,
                'organization_id' => $organizationId,
                'actor_id' => $actor->id,
                'source_version_id' => $locked->current_version_id,
                'operation_type' => $targetVersionId !== null ? 'restore' : 'revision',
                'target_version_id' => $targetVersionId,
                'idempotency_key' => $key,
                'reason' => $reason,
                'status' => 'queued',
            ]);
        });

        if ($operation->wasRecentlyCreated) {
            try {
                $this->dispatch($operation);
            } catch (Throwable $exception) {
                $this->fail($operation->id, $exception, 'dispatch_failed');
                throw $exception;
            }
        }

        return $operation->refresh();
    }

    public function latest(int $estimateId, int $organizationId, User $actor): ?EstimateRevisionOperation
    {
        $estimate = Estimate::query()->whereKey($estimateId)->where('organization_id', $organizationId)->firstOrFail();
        Gate::forUser($actor)->authorize('view', $estimate);

        return EstimateRevisionOperation::query()->where('estimate_id', $estimateId)
            ->where('organization_id', $organizationId)->orderByDesc('sequence')->first();
    }

    public function process(string $id): void
    {
        try {
            EstimateRevisionOperation::query()->whereKey($id)->where('status', 'queued')
                ->update(['status' => 'processing', 'started_at' => now(), 'updated_at' => now()]);
            DB::transaction(function () use ($id): void {
                $operation = EstimateRevisionOperation::query()->whereKey($id)->lock('FOR UPDATE SKIP LOCKED')->first();
                if ($operation === null || ! in_array($operation->status, ['queued', 'processing'], true)) {
                    return;
                }
                Log::channel('estimate_revisions')->info('revision.processing', $this->context($operation));
                $actor = User::query()->findOrFail($operation->actor_id);
                $actor->current_organization_id = $operation->organization_id;
                $estimate = $this->authorize($operation->estimate_id, $operation->organization_id, $actor, $operation->operation_type === 'restore');
                $estimate = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
                if ((int) $estimate->current_version_id !== (int) $operation->source_version_id) {
                    throw new DomainException('revision_source_changed');
                }
                if ($operation->operation_type === 'restore') {
                    $version = EstimateVersion::query()->where('estimate_id', $estimate->id)
                        ->where('organization_id', $operation->organization_id)->findOrFail($operation->target_version_id);
                    app(EstimateVersionRestoreService::class)->restore($estimate, $version, $actor->id);
                } else {
                    $this->revisions->start($estimate, $actor->id, $operation->reason, $operation->idempotency_key);
                }
                $operation->update(['status' => 'completed', 'finished_at' => now()]);
                Log::channel('estimate_revisions')->info('revision.completed', $this->context($operation));
            });
        } catch (Throwable $exception) {
            $this->fail($id, $exception);
            throw $exception;
        }
    }

    public function fail(string $id, ?Throwable $exception, string $code = 'processing_failed'): void
    {
        DB::transaction(function () use ($id, $exception, $code): void {
            $operation = EstimateRevisionOperation::query()->whereKey($id)->lock('FOR UPDATE SKIP LOCKED')->first();
            if ($operation === null || ! in_array($operation->status, ['queued', 'processing'], true)) {
                return;
            }
            $operation->update(['status' => 'failed', 'error_code' => $code, 'finished_at' => now()]);
            $this->logError($exception, $this->context($operation) + ['error_code' => $code]);
        });
    }

    public function recover(): void
    {
        $ids = EstimateRevisionOperation::query()->whereIn('status', ['queued', 'processing'])
            ->where('updated_at', '<', now()->subMinutes(12))->orderBy('updated_at')->limit(50)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $operation = EstimateRevisionOperation::query()->whereKey($id)->lock('FOR UPDATE SKIP LOCKED')->first();
                if ($operation === null || ! in_array($operation->status, ['queued', 'processing'], true)
                    || $operation->updated_at->greaterThan(now()->subMinutes(12))) {
                    return;
                }
                if ($operation->dispatch_attempts >= 3) {
                    $this->fail($id, null, 'worker_unavailable');

                    return;
                }
                try {
                    $this->dispatch($operation);
                } catch (Throwable $exception) {
                    $this->fail($id, $exception, 'dispatch_failed');
                }
            });
        }
    }

    public function logError(?Throwable $exception, array $context): void
    {
        Log::channel('estimate_revisions')->error('revision.failed', $context + [
            'exception_class' => $exception !== null ? $exception::class : null,
            'exception_code' => $exception?->getCode(),
            'file' => $exception?->getFile(),
            'line' => $exception?->getLine(),
            'trace' => array_map(static fn (array $frame): array => array_intersect_key($frame,
                array_flip(['file', 'line', 'class', 'function'])), array_slice($exception?->getTrace() ?? [], 0, 20)),
        ]);
    }

    private function assertSameTarget(EstimateRevisionOperation $operation, ?int $targetVersionId): void
    {
        if ($operation->target_version_id !== $targetVersionId) {
            throw new DomainException('operation_key_reused');
        }
    }

    private function authorize(int $estimateId, int $organizationId, User $actor, bool $restore = false): Estimate
    {
        $estimate = Estimate::query()->whereKey($estimateId)->where('organization_id', $organizationId)->firstOrFail();
        Gate::forUser($actor)->authorize($restore ? 'rollbackVersion' : 'createVersion', $estimate);
        if (! $restore) {
            Gate::forUser($actor)->authorize('update', $estimate);
        }

        return $estimate;
    }

    private function dispatch(EstimateRevisionOperation $operation): void
    {
        $operation->update(['dispatched_at' => now(), 'dispatch_attempts' => $operation->dispatch_attempts + 1]);
        Queue::connection('redis_estimate_revisions')->push(new CreateEstimateRevision($operation->id), '', 'estimate-revisions');
        Log::channel('estimate_revisions')->info('revision.queued', $this->context($operation));
    }

    private function context(EstimateRevisionOperation $operation): array
    {
        return ['operation_id' => $operation->id, 'estimate_id' => $operation->estimate_id,
            'operation_type' => $operation->operation_type, 'target_version_id' => $operation->target_version_id,
            'organization_id' => $operation->organization_id, 'actor_id' => $operation->actor_id];
    }
}
