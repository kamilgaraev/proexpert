<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCaptureBoundary;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityClock;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCurrentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityPolicySource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacitySnapshotStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureCommand;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCaptureResult;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use InvalidArgumentException;

final readonly class WorkforceCapacitySnapshotService implements WorkforceCapacityCaptureBoundary
{
    public function __construct(
        private WorkforceCapacityCurrentSource $source,
        private WorkforceCapacityPolicySource $policies,
        private WorkforceCapacitySnapshotStore $store,
        private WorkforceCapacityClock $clock,
        private int $chunkSize = 64,
    ) {
        if ($this->chunkSize < 1 || $this->chunkSize > 500) {
            throw new InvalidArgumentException('workforce_capacity_chunk_size_invalid');
        }
    }

    public function capture(WorkforceCapacityCaptureCommand $command): WorkforceCapacityCaptureResult
    {
        $policy = $this->policies->forOrganization($command->organizationId);
        $capturedAt = $this->clock->now();
        if ($capturedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('workforce_capacity_clock_must_be_utc');
        }
        $localToday = $capturedAt->setTimezone(new \DateTimeZone($policy->timezone))->format('Y-m-d');
        $builder = new WorkforceCapacitySnapshotBuilder($policy);
        $buffer = [];
        $snapshotCount = 0;
        $chunkCount = 0;
        $cursor = null;
        $lastSortIdentity = null;

        $flush = function () use (
            &$buffer,
            &$snapshotCount,
            &$chunkCount,
            &$cursor,
            $builder,
            $capturedAt,
            $command,
        ): void {
            if ($buffer === []) {
                return;
            }
            $sources = $this->source->readBatch($command, $buffer);
            $snapshots = [];
            foreach ($buffer as $key) {
                $source = $sources[$key->identity()] ?? null;
                if (! is_array($source)) {
                    throw new InvalidArgumentException('workforce_capacity_source_batch_incomplete');
                }
                $snapshots[] = $builder->build(
                    key: $key,
                    captureKind: $command->captureKind,
                    capturedAt: $capturedAt,
                    actorUserId: $command->actorUserId,
                    serviceActor: $command->serviceActor,
                    source: $source,
                );
            }

            $priorCursor = $cursor;
            $cursor = hash('sha256', json_encode([
                'mutation_id' => $command->mutationId,
                'prior_cursor' => $priorCursor,
                'cohorts' => array_map(
                    static fn ($snapshot): array => [
                        'identity' => $snapshot->key->identity(),
                        'source_hash' => $snapshot->sourceHash,
                    ],
                    $snapshots,
                ),
            ], JSON_THROW_ON_ERROR));
            $this->store->appendBatch($command->mutationId, $priorCursor, $cursor, $snapshots);
            $snapshotCount += count($snapshots);
            $chunkCount++;
            $buffer = [];
        };

        foreach ($this->source->affectedCohorts($command, $localToday) as $key) {
            if (! $key instanceof WorkforceCapacityCohortKey
                || $key->organizationId !== $command->organizationId
                || $key->asOfDate < $localToday
                || ($lastSortIdentity !== null && strcmp($lastSortIdentity, $key->sortIdentity()) >= 0)) {
                throw new InvalidArgumentException('workforce_capacity_cohort_stream_invalid');
            }
            $lastSortIdentity = $key->sortIdentity();
            $buffer[] = $key;
            if (count($buffer) === $this->chunkSize) {
                $flush();
            }
        }
        $flush();
        $this->store->completeCapture(
            $command->mutationId,
            $command->organizationId,
            $cursor,
            $snapshotCount,
            $chunkCount,
        );

        return new WorkforceCapacityCaptureResult($snapshotCount, $chunkCount, $cursor);
    }
}
