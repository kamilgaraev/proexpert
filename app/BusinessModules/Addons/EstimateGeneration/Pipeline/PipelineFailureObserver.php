<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface PipelineFailureObserver
{
    public function checkpointFailureWasNotRecorded(
        CheckpointClaim $claim,
        PipelineFailureDetails $stageFailure,
        ?PipelineFailureDetails $recorderFailure,
    ): void;
}
