<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use DateTimeImmutable;

interface FailureStore
{
    public function record(FailureData $failure, DateTimeImmutable $seenAt): void;

    public function resolve(
        FailureContext $context,
        string $fingerprint,
        string $resolutionCode,
        DateTimeImmutable $resolvedAt,
    ): bool;

    public function resolveActive(
        FailureContext $context,
        string $resolutionCode,
        DateTimeImmutable $resolvedAt,
    ): int;
}
