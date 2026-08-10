<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;

interface VisionPhysicalAttemptStore
{
    public function reserve(AiOperationContext $context, string $requestFingerprint): VisionPhysicalAttemptSnapshot;

    /** @param array<string, mixed> $responsePayload @param array<string, mixed> $priceSnapshot */
    public function storeResponse(
        string $attemptId,
        string $requestFingerprint,
        array $responsePayload,
        string $status,
        ?int $httpCode,
        int $durationMs,
        ?string $reportedModel,
        array $priceSnapshot,
    ): void;

    public function markUsageRecorded(string $attemptId, string $requestFingerprint): void;
}
