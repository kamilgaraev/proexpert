<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DateTimeImmutable;

final class NullPipelineCompletionHook implements PipelineCompletionHook
{
    public function beforeComplete(CheckpointClaim $claim, PipelineStageResult $result, DateTimeImmutable $completedAt): void {}
}
