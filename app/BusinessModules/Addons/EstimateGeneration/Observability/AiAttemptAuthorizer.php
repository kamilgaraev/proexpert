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

    public function markSent(string $attemptId): void;

    public function releaseBeforeWire(string $attemptId): void;
}
