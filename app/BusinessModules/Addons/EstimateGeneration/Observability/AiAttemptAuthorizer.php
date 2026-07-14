<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

interface AiAttemptAuthorizer
{
    public function authorize(
        AiOperationContext $context,
        string $provider,
        string $model,
        int $maxInputTokens,
        int $maxOutputTokens,
        int $imageCount = 0,
        int $pageCount = 0,
    ): AiPriceSnapshot;

    public function claimWire(string $attemptId): bool;

    public function releaseBeforeWire(string $attemptId): void;
}
