<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStore;
use DateTimeImmutable;

final class InMemoryVisionPhysicalAttemptStore implements VisionPhysicalAttemptStore
{
    /** @var array<string, array{fingerprint: string, snapshot: VisionPhysicalAttemptSnapshot}> */
    private array $attempts = [];

    /** @var list<AiCost> */
    private array $costReservations = [];

    /** @return list<AiCost> */
    public function costReservations(): array
    {
        return $this->costReservations;
    }

    public function onlySnapshot(): VisionPhysicalAttemptSnapshot
    {
        if (count($this->attempts) !== 1) {
            throw new \LogicException('Expected exactly one physical attempt.');
        }

        return array_values($this->attempts)[0]['snapshot'];
    }

    /** @return list<VisionPhysicalAttemptSnapshot> */
    public function snapshots(): array
    {
        return array_values(array_map(
            static fn (array $attempt): VisionPhysicalAttemptSnapshot => $attempt['snapshot'],
            $this->attempts,
        ));
    }

    public function claim(AiOperationContext $context, string $requestFingerprint, string $ownerToken, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): VisionPhysicalAttemptSnapshot
    {
        $row = $this->attempts[$context->attemptId] ?? null;
        if ($row !== null) {
            if (! hash_equals($row['fingerprint'], $requestFingerprint)) {
                throw new \App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
            }

            return $row['snapshot'];
        }
        $snapshot = new VisionPhysicalAttemptSnapshot(true, 'pre_wire', ownerToken: $ownerToken, leaseExpiresAt: $leaseExpiresAt);
        $this->attempts[$context->attemptId] = ['fingerprint' => $requestFingerprint, 'snapshot' => $snapshot];

        return $snapshot;
    }

    public function markWireStarted(string $attemptId, string $requestFingerprint, string $ownerToken, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt, \App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost $costReservation = new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost(null, null, 'unavailable')): void
    {
        $row = $this->attempts[$attemptId] ?? null;
        if ($row === null || ! hash_equals($row['fingerprint'], $requestFingerprint)
            || $row['snapshot']->state !== 'pre_wire' || $row['snapshot']->ownerToken !== $ownerToken) {
            throw new \App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
        }
        $this->costReservations[] = $costReservation;
        $this->attempts[$attemptId]['snapshot'] = new VisionPhysicalAttemptSnapshot(
            false, 'wire_started', usageRecorded: false, ownerToken: $ownerToken, leaseExpiresAt: $leaseExpiresAt,
        );
    }

    public function storeResponse(
        string $attemptId,
        string $requestFingerprint,
        string $ownerToken,
        array $responsePayload,
        string $status,
        ?int $httpCode,
        int $durationMs,
        ?string $reportedModel,
        array $priceSnapshot,
    ): void {
        $row = $this->attempts[$attemptId] ?? null;
        if ($row === null || ! hash_equals($row['fingerprint'], $requestFingerprint)
            || $row['snapshot']->state !== 'wire_started' || $row['snapshot']->ownerToken !== $ownerToken) {
            throw new \App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
        }
        $this->attempts[$attemptId]['snapshot'] = new VisionPhysicalAttemptSnapshot(
            false, 'response_received', $responsePayload, $status, $httpCode, $durationMs,
            $reportedModel, $priceSnapshot, $row['snapshot']->usageRecorded,
        );
    }

    public function markAmbiguous(string $attemptId, string $requestFingerprint, string $ownerToken, string $reason, DateTimeImmutable $now, int $durationMs, ?int $httpCode, ?string $reportedModel, array $priceSnapshot): void
    {
        $row = $this->attempts[$attemptId] ?? null;
        if ($row === null || ! hash_equals($row['fingerprint'], $requestFingerprint)
            || $row['snapshot']->state !== 'wire_started' || $row['snapshot']->ownerToken !== $ownerToken) {
            throw new \App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
        }
        $this->attempts[$attemptId]['snapshot'] = new VisionPhysicalAttemptSnapshot(
            false,
            'ambiguous',
            status: 'ambiguous',
            httpCode: $httpCode,
            durationMs: $durationMs,
            reportedModel: $reportedModel,
            priceSnapshot: $priceSnapshot,
            terminalReason: $reason,
        );
    }

    public function markUsageRecorded(string $attemptId, string $requestFingerprint): void
    {
        $row = $this->attempts[$attemptId] ?? null;
        if ($row === null || ! hash_equals($row['fingerprint'], $requestFingerprint)) {
            throw new \App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
        }
        $snapshot = $row['snapshot'];
        $this->attempts[$attemptId]['snapshot'] = new VisionPhysicalAttemptSnapshot(
            false, $snapshot->state === 'response_received' ? 'completed' : $snapshot->state,
            $snapshot->responsePayload, $snapshot->status, $snapshot->httpCode,
            $snapshot->durationMs, $snapshot->reportedModel, $snapshot->priceSnapshot, true,
            terminalReason: $snapshot->terminalReason,
        );
    }
}
