<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DateTimeImmutable;
use Throwable;

interface PipelineCheckpointStore
{
    public function claim(
        PipelineContext $context,
        ProcessingStage $stage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseExpiresAt,
    ): CheckpointClaim;

    public function complete(
        CheckpointClaim $claim,
        PipelineStageResult $result,
        DateTimeImmutable $completedAt,
    ): bool;

    public function renewLease(
        CheckpointClaim $claim,
        DateTimeImmutable $now,
        DateTimeImmutable $newLeaseExpiresAt,
    ): bool;

    public function fail(CheckpointClaim $claim, Throwable $error, DateTimeImmutable $failedAt): bool;
}
