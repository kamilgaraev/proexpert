<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use DateTimeImmutable;

interface VisionPhysicalAttemptStore
{
    public function claim(
        AiOperationContext $context,
        string $requestFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseExpiresAt,
    ): VisionPhysicalAttemptSnapshot;

    public function markWireStarted(
        string $attemptId,
        string $requestFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseExpiresAt,
        AiCost $costReservation = new AiCost(null, null, 'unavailable'),
    ): void;

    /** @param array<string, mixed> $responsePayload @param array<string, mixed> $priceSnapshot */
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
    ): void;

    /** @param array<string, mixed> $priceSnapshot */
    public function markAmbiguous(
        string $attemptId,
        string $requestFingerprint,
        string $ownerToken,
        string $reason,
        DateTimeImmutable $now,
        int $durationMs,
        ?int $httpCode,
        ?string $reportedModel,
        array $priceSnapshot,
    ): void;

    public function markUsageRecorded(string $attemptId, string $requestFingerprint): void;
}
