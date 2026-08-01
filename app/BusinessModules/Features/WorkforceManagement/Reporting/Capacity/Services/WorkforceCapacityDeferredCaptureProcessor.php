<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureDispatcher;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredCaptureStore;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityDeferredSourceReader;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityDeferredCaptureClaim;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class WorkforceCapacityDeferredCaptureProcessor
{
    private const CHUNK_SIZE = 1;

    public function __construct(
        private WorkforceCapacityDeferredCaptureStore $store,
        private WorkforceCapacityDeferredSourceReader $source,
        private WorkforceCapacityDeferredCaptureDispatcher $dispatcher,
        private WorkforceCapacitySnapshotEvaluatorRegistry $evaluators,
        private int $maximumAttempts = 5,
        private int $leaseSeconds = 960,
    ) {}

    public function process(int $captureRequestId): void
    {
        $now = $this->now();
        $claim = $this->store->claim($captureRequestId, $now, $this->leaseSeconds);
        if (! $claim instanceof WorkforceCapacityDeferredCaptureClaim) {
            return;
        }

        if ($claim->attemptCount > $this->maximumAttempts) {
            $this->store->failClaim(
                $claim,
                'workforce_capacity_attempts_exhausted',
                $now,
                true,
            );

            return;
        }

        try {
            $keys = $this->source->nextKeys($captureRequestId, $claim->cohortCursor, self::CHUNK_SIZE + 1);
            if ($keys === []) {
                $this->store->failClaim(
                    $claim,
                    'workforce_capacity_deferred_plan_exhausted',
                    $this->now(),
                    true,
                );

                return;
            }
            $completed = count($keys) <= self::CHUNK_SIZE;
            $keys = array_slice($keys, 0, self::CHUNK_SIZE);
            $sources = $this->source->readBatch($captureRequestId, $keys);
            $evaluator = $this->evaluators->resolve(
                $claim->pins->sourceSchemaVersion,
                $claim->pins->formulaVersion,
                $claim->pins->policy,
            );
            $snapshots = [];
            foreach ($keys as $key) {
                $source = $sources[$key->identity()] ?? null;
                if (! is_array($source)) {
                    throw new \LogicException('workforce_capacity_deferred_source_batch_incomplete');
                }
                $snapshots[] = $evaluator->evaluate(
                    key: $key,
                    captureKind: $claim->pins->command->captureKind,
                    capturedAt: $claim->pins->capturedAt,
                    actorUserId: $claim->pins->command->actorUserId,
                    serviceActor: $claim->pins->command->serviceActor,
                    source: $source,
                );
            }
            $cohortCursor = $keys[array_key_last($keys)]->sortIdentity();
            $hashCursor = hash('sha256', json_encode([
                'mutation_id' => $claim->pins->command->mutationId,
                'prior_cursor' => $claim->hashCursor,
                'cohorts' => array_map(static fn ($snapshot): array => [
                    'identity' => $snapshot->key->identity(),
                    'source_hash' => $snapshot->sourceHash,
                ], $snapshots),
            ], JSON_THROW_ON_ERROR));
            $advanced = $this->store->appendClaimedChunk(
                $claim,
                $cohortCursor,
                $hashCursor,
                $snapshots,
                $completed,
                $this->now(),
            );
            if ($advanced && ! $completed) {
                $this->dispatcher->dispatchAfterCommit($captureRequestId);
            }
        } catch (Throwable) {
            $deadLettered = $claim->attemptCount >= $this->maximumAttempts;
            $retryAt = $this->now()->modify('+'.$this->backoffSeconds($claim->attemptCount).' seconds');
            if ($this->store->failClaim(
                $claim,
                'workforce_capacity_materialization_failed',
                $retryAt,
                $deadLettered,
            ) && ! $deadLettered) {
                $this->dispatcher->dispatchAfterCommit($captureRequestId);
            }
        }
    }

    private function backoffSeconds(int $attempt): int
    {
        return min(3600, 15 * (2 ** max(0, $attempt - 1)));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
