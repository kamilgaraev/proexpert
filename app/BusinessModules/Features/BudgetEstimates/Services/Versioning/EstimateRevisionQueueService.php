<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\Jobs\CreateEstimateRevision;
use App\Models\Estimate;
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

    public function enqueue(int $estimateId, int $organizationId, User $actor, string $reason, string $key): EstimateRevisionOperation
    {
        $estimate = Estimate::query()->whereKey($estimateId)->where('organization_id', $organizationId)->firstOrFail();
        Gate::forUser($actor)->authorize('view', $estimate);
        $existing = EstimateRevisionOperation::query()->where('estimate_id', $estimateId)
            ->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $this->authorize($estimateId, $organizationId, $actor);

        $operation = DB::transaction(function () use ($estimate, $organizationId, $actor, $reason, $key): EstimateRevisionOperation {
            $locked = Estimate::query()->whereKey($estimate->id)->lock('FOR UPDATE NOWAIT')->firstOrFail();
            $existing = EstimateRevisionOperation::query()->where('estimate_id', $locked->id)
                ->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            if (EstimateRevisionOperation::query()->where('estimate_id', $locked->id)
                ->whereIn('status', ['queued', 'processing'])->exists()) {
                throw new DomainException(trans_message('estimate.revision_already_pending'));
            }
            if ($locked->status !== 'approved' || $locked->current_version_id === null) {
                throw new DomainException(trans_message('estimate.revision_requires_approved_estimate'));
            }

            return EstimateRevisionOperation::query()->create([
                'id' => (string) Str::uuid(),
                'estimate_id' => $locked->id,
                'organization_id' => $organizationId,
                'actor_id' => $actor->id,
                'source_version_id' => $locked->current_version_id,
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
            ->where('organization_id', $organizationId)->orderByDesc('created_at')->orderByDesc('id')->first();
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
                $estimate = $this->authorize($operation->estimate_id, $operation->organization_id, $actor);
                $estimate = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
                if ((int) $estimate->current_version_id !== $operation->source_version_id) {
                    throw new DomainException('revision_source_changed');
                }
                $this->revisions->start($estimate, $actor->id, $operation->reason, $operation->idempotency_key);
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
        $operation = EstimateRevisionOperation::query()->find($id);
        if ($operation === null) {
            return;
        }
        $changed = EstimateRevisionOperation::query()->whereKey($id)->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'failed', 'error_code' => $code, 'finished_at' => now(), 'updated_at' => now()]);
        if ($changed) {
            $this->logError($exception, $this->context($operation) + ['error_code' => $code]);
        }
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

    private function authorize(int $estimateId, int $organizationId, User $actor): Estimate
    {
        $estimate = Estimate::query()->whereKey($estimateId)->where('organization_id', $organizationId)->firstOrFail();
        Gate::forUser($actor)->authorize('createVersion', $estimate);
        Gate::forUser($actor)->authorize('update', $estimate);

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
            'organization_id' => $operation->organization_id, 'actor_id' => $operation->actor_id];
    }
}
