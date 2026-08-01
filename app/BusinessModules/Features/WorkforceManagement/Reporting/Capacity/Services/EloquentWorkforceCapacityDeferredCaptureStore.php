<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacitySnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityDeferredCaptureClaim;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCapturePins;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacitySnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models\WorkforceCapacityCaptureRequestRecord;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final readonly class EloquentWorkforceCapacityDeferredCaptureStore implements WorkforceCapacityDeferredCaptureStore
{
    public function __construct(private WorkforceCapacitySnapshotStore $snapshots) {}

    public function claim(int $captureRequestId, DateTimeImmutable $at, int $leaseSeconds): ?WorkforceCapacityDeferredCaptureClaim
    {
        $claimed = DB::transaction(function () use ($captureRequestId, $at, $leaseSeconds): ?array {
            $table = (new WorkforceCapacityCaptureRequestRecord)->getTable();
            $record = DB::table($table)->where('id', $captureRequestId)->lockForUpdate()->first();
            if (! is_object($record)
                || in_array((string) $record->status, ['completed', 'dead_lettered'], true)
                || $record->frozen_at === null
                || $record->available_at === null
                || new DateTimeImmutable((string) $record->available_at) > $at
                || ((string) $record->status === 'processing'
                    && $record->claimed_at !== null
                    && new DateTimeImmutable((string) $record->claimed_at) > $at->modify("-{$leaseSeconds} seconds"))) {
                return null;
            }
            $claimToken = (string) Str::uuid();
            $attemptCount = (int) $record->attempt_count + 1;
            $updated = DB::table($table)->where('id', $captureRequestId)->update([
                'status' => 'processing',
                'claim_token' => $claimToken,
                'claimed_at' => $at,
                'attempt_count' => $attemptCount,
            ]);
            if ($updated !== 1) {
                throw new LogicException('workforce_capacity_deferred_claim_failed');
            }

            return [$record, $claimToken, $attemptCount];
        });
        if ($claimed === null) {
            return null;
        }

        [$record, $claimToken, $attemptCount] = $claimed;
        try {
            $pins = WorkforceCapacityFrozenCapturePins::fromCanonical(
                commandCanonical: (string) $record->command_canonical,
                commandHash: (string) $record->command_hash,
                policyCanonical: (string) $record->policy_canonical,
                policyHash: (string) $record->policy_hash,
                capturedAt: new DateTimeImmutable((string) $record->captured_at),
                businessDate: (string) $record->business_date,
                sourceSchemaVersion: (string) $record->source_schema_version,
                formulaVersion: (string) $record->formula_version,
            );
        } catch (Throwable) {
            DB::table((new WorkforceCapacityCaptureRequestRecord)->getTable())
                ->where('id', $captureRequestId)
                ->where('status', 'processing')
                ->where('claim_token', $claimToken)
                ->update([
                    'status' => 'dead_lettered',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'last_error_code' => 'workforce_capacity_frozen_pins_invalid',
                    'dead_lettered_at' => $at,
                ]);

            return null;
        }

        return new WorkforceCapacityDeferredCaptureClaim(
            requestId: $captureRequestId,
            claimToken: $claimToken,
            pins: $pins,
            cohortCursor: $record->cohort_cursor === null ? null : (string) $record->cohort_cursor,
            hashCursor: $record->current_cursor === null ? null : (string) $record->current_cursor,
            snapshotCount: (int) $record->snapshot_count,
            chunkCount: (int) $record->chunk_count,
            attemptCount: $attemptCount,
        );
    }

    public function appendClaimedChunk(
        WorkforceCapacityDeferredCaptureClaim $claim,
        string $cohortCursor,
        string $hashCursor,
        array $snapshots,
        bool $completed,
        DateTimeImmutable $at,
    ): bool {
        $keys = [];
        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof WorkforceCapacitySnapshot) {
                throw new LogicException('workforce_capacity_batch_order_invalid');
            }

            $keys[] = $snapshot->key;
        }
        (new WorkforceCapacitySnapshotBatchOrder)->assertKeys($keys);

        return DB::transaction(function () use ($claim, $cohortCursor, $hashCursor, $snapshots, $completed, $at): bool {
            $table = (new WorkforceCapacityCaptureRequestRecord)->getTable();
            $record = DB::table($table)
                ->where('id', $claim->requestId)
                ->where('status', 'processing')
                ->where('claim_token', $claim->claimToken)
                ->where('current_cursor', $claim->hashCursor)
                ->lockForUpdate()
                ->first();
            if (! is_object($record)) {
                return false;
            }

            $this->snapshots->appendBatch(
                $claim->pins->command->mutationId,
                $claim->hashCursor,
                $hashCursor,
                $snapshots,
            );

            $record = DB::table($table)
                ->where('id', $claim->requestId)
                ->where('status', 'processing')
                ->where('claim_token', $claim->claimToken)
                ->where('current_cursor', $hashCursor)
                ->lockForUpdate()
                ->first();
            if (! is_object($record)) {
                throw new LogicException('workforce_capacity_deferred_progress_mismatch');
            }

            $expectedSnapshots = $claim->snapshotCount + count($snapshots);
            $expectedChunks = $claim->chunkCount + 1;
            if ((int) $record->snapshot_count !== $expectedSnapshots || (int) $record->chunk_count !== $expectedChunks) {
                throw new LogicException('workforce_capacity_deferred_progress_mismatch');
            }
            DB::table($table)->where('id', $claim->requestId)->update([
                'cohort_cursor' => $cohortCursor,
                'status' => $completed ? 'processing' : 'pending',
                'available_at' => $at,
                'claim_token' => null,
                'claimed_at' => null,
                'attempt_count' => 0,
                'last_error_code' => null,
            ]);
            if ($completed) {
                $this->snapshots->completeCapture(
                    $claim->pins->command->mutationId,
                    $claim->pins->command->organizationId,
                    $hashCursor,
                    $expectedSnapshots,
                    $expectedChunks,
                );
            }

            return true;
        });
    }

    public function failClaim(
        WorkforceCapacityDeferredCaptureClaim $claim,
        string $safeErrorCode,
        DateTimeImmutable $retryAt,
        bool $deadLettered,
    ): bool {
        return DB::table((new WorkforceCapacityCaptureRequestRecord)->getTable())
            ->where('id', $claim->requestId)
            ->where('status', 'processing')
            ->where('claim_token', $claim->claimToken)
            ->update([
                'status' => $deadLettered ? 'dead_lettered' : 'pending',
                'available_at' => $retryAt,
                'claim_token' => null,
                'claimed_at' => null,
                'last_error_code' => $safeErrorCode,
                'dead_lettered_at' => $deadLettered ? $retryAt : null,
            ]) === 1;
    }

    public function recoverableIds(DateTimeImmutable $at, int $limit, int $leaseSeconds): array
    {
        $limit = max(1, min($limit, 100));

        return DB::table((new WorkforceCapacityCaptureRequestRecord)->getTable())
            ->where('available_at', '<=', $at)
            ->where(function ($query) use ($at, $leaseSeconds): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale) use ($at, $leaseSeconds): void {
                        $stale->where('status', 'processing')
                            ->where('claimed_at', '<=', $at->modify("-{$leaseSeconds} seconds"));
                    });
            })
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
