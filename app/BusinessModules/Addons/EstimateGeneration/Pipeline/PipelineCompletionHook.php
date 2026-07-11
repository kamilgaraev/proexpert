<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DateTimeImmutable;

interface PipelineCompletionHook
{
    public function beforeComplete(CheckpointClaim $claim, PipelineStageResult $result, DateTimeImmutable $completedAt): void;
}
