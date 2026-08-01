<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCohortLock;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacitySnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityEvidenceItem;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacitySnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models\WorkforceCapacityCaptureRequestRecord;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models\WorkforceCapacitySnapshotItemRecord;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models\WorkforceCapacitySnapshotRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class EloquentWorkforceCapacitySnapshotStore implements WorkforceCapacitySnapshotStore
{
    public function __construct(private WorkforceCapacityCohortLock $cohortLock) {}

    public function appendBatch(
        string $mutationId,
        ?string $priorCursor,
        string $cursor,
        array $snapshots,
    ): void {
        if ($snapshots === []) {
            throw new LogicException('workforce_capacity_empty_batch');
        }

        $keys = [];
        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof WorkforceCapacitySnapshot) {
                throw new LogicException('workforce_capacity_snapshot_type_invalid');
            }

            $keys[] = $snapshot->key;
        }
        (new WorkforceCapacitySnapshotBatchOrder)->assertKeys($keys);

        DB::transaction(function () use ($mutationId, $priorCursor, $cursor, $snapshots): void {
            $organizationId = $this->batchOrganizationId($snapshots);
            $request = $this->lockedRequest($mutationId, $organizationId);
            if ($request->status === 'completed') {
                return;
            }
            if ($request->current_cursor === $cursor) {
                return;
            }
            if ($request->current_cursor !== $priorCursor) {
                throw new LogicException('workforce_capacity_capture_cursor_mismatch');
            }

            foreach ($snapshots as $snapshot) {
                $this->appendSnapshot((int) $request->id, $mutationId, $cursor, $snapshot);
            }

            DB::table((new WorkforceCapacityCaptureRequestRecord)->getTable())
                ->where('id', $request->id)
                ->where('status', 'processing')
                ->update([
                    'current_cursor' => $cursor,
                    'snapshot_count' => (int) $request->snapshot_count + count($snapshots),
                    'chunk_count' => (int) $request->chunk_count + 1,
                ]);
        });
    }

    public function completeCapture(
        string $mutationId,
        int $organizationId,
        ?string $cursor,
        int $snapshotCount,
        int $chunkCount,
    ): void {
        DB::transaction(function () use ($mutationId, $organizationId, $cursor, $snapshotCount, $chunkCount): void {
            $request = $this->lockedRequest($mutationId, $organizationId);
            if ($request->status === 'completed') {
                if ($request->current_cursor !== $cursor
                    || (int) $request->snapshot_count !== $snapshotCount
                    || (int) $request->chunk_count !== $chunkCount) {
                    throw new LogicException('workforce_capacity_completed_capture_mismatch');
                }

                return;
            }
            if ($request->current_cursor !== $cursor
                || (int) $request->snapshot_count !== $snapshotCount
                || (int) $request->chunk_count !== $chunkCount) {
                throw new LogicException('workforce_capacity_capture_completion_mismatch');
            }
            DB::table((new WorkforceCapacityCaptureRequestRecord)->getTable())
                ->where('id', $request->id)
                ->where('status', 'processing')
                ->update([
                    'status' => 'completed',
                    'completed_at' => $this->now(),
                ]);
        });
    }

    private function appendSnapshot(int $captureRequestId, string $mutationId, string $cursor, mixed $snapshot): void
    {
        if (! $snapshot instanceof WorkforceCapacitySnapshot) {
            throw new LogicException('workforce_capacity_snapshot_type_invalid');
        }
        $this->cohortLock->acquire($snapshot->key);
        $existing = WorkforceCapacitySnapshotRecord::query()
            ->where('capture_request_id', $captureRequestId)
            ->where('organization_id', $snapshot->key->organizationId)
            ->where('month_start', $snapshot->key->monthStart)
            ->where('as_of_date', $snapshot->key->asOfDate)
            ->where('staff_unit_id', $snapshot->key->staffUnitId)
            ->when(
                $snapshot->key->projectId === null,
                static fn ($query) => $query->whereNull('project_id'),
                static fn ($query) => $query->where('project_id', $snapshot->key->projectId),
            )
            ->where('capture_kind', $snapshot->captureKind)
            ->where('source_hash', $snapshot->sourceHash)
            ->first();
        if ($existing instanceof WorkforceCapacitySnapshotRecord) {
            return;
        }

        $payload = $snapshot->toPersistence();
        unset($payload['semantic_label']);
        $record = WorkforceCapacitySnapshotRecord::query()->create([
            ...$payload,
            'capture_request_id' => $captureRequestId,
            'capture_mutation_id' => $mutationId,
            'capture_cursor' => $cursor,
            'sealed_at' => null,
        ]);
        $position = 0;
        $itemsHashPayload = [];
        $batch = [];
        foreach ($snapshot->items as $item) {
            if (! $item instanceof WorkforceCapacityEvidenceItem) {
                throw new LogicException('workforce_capacity_item_type_invalid');
            }
            $position++;
            $itemsHashPayload[] = [
                'position' => $position,
                'type' => $item->sourceType,
                'content_hash' => $item->contentHash,
            ];
            $batch[] = [
                ...(new WorkforceCapacityEvidenceBulkPersistence)->row($item, $position),
                'workforce_capacity_snapshot_id' => $record->getKey(),
                'organization_id' => $snapshot->key->organizationId,
                'staff_unit_id' => $snapshot->key->staffUnitId,
                'project_id' => $snapshot->key->projectId,
                'month_start' => $snapshot->key->monthStart,
                'created_at' => $snapshot->capturedAt,
            ];
            if (count($batch) === 500) {
                DB::table((new WorkforceCapacitySnapshotItemRecord)->getTable())->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table((new WorkforceCapacitySnapshotItemRecord)->getTable())->insert($batch);
        }
        if ($position !== $snapshot->itemCount
            || hash('sha256', json_encode($itemsHashPayload, JSON_THROW_ON_ERROR)) !== $snapshot->itemsHash) {
            throw new LogicException('workforce_capacity_evidence_stream_changed');
        }
        $sealed = DB::table((new WorkforceCapacitySnapshotRecord)->getTable())
            ->where('id', $record->getKey())
            ->whereNull('sealed_at')
            ->update(['sealed_at' => $this->now()]);
        if ($sealed !== 1) {
            throw new LogicException('workforce_capacity_snapshot_seal_failed');
        }
    }

    private function lockedRequest(string $mutationId, int $organizationId): object
    {
        DB::table((new WorkforceCapacityCaptureRequestRecord)->getTable())->insertOrIgnore([
            'organization_id' => $organizationId,
            'mutation_id' => $mutationId,
            'status' => 'processing',
            'current_cursor' => null,
            'snapshot_count' => 0,
            'chunk_count' => 0,
            'started_at' => $this->now(),
            'completed_at' => null,
        ]);
        $request = DB::table((new WorkforceCapacityCaptureRequestRecord)->getTable())
            ->where('organization_id', $organizationId)
            ->where('mutation_id', $mutationId)
            ->lockForUpdate()
            ->first();
        if (! is_object($request)) {
            throw new LogicException('workforce_capacity_capture_request_missing');
        }

        return $request;
    }

    private function batchOrganizationId(array $snapshots): int
    {
        $organizationId = null;
        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof WorkforceCapacitySnapshot) {
                throw new LogicException('workforce_capacity_snapshot_type_invalid');
            }
            $organizationId ??= $snapshot->key->organizationId;
            if ($snapshot->key->organizationId !== $organizationId) {
                throw new LogicException('workforce_capacity_batch_organization_mismatch');
            }
        }

        return (int) $organizationId;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
