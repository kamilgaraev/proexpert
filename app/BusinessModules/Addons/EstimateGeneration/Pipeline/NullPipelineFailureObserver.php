<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

final class NullPipelineFailureObserver implements PipelineFailureObserver
{
    public function checkpointFailureWasNotRecorded(
        CheckpointClaim $claim,
        PipelineFailureDetails $stageFailure,
        ?PipelineFailureDetails $recorderFailure,
    ): void {}
}
