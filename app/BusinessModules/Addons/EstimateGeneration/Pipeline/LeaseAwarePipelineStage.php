<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface LeaseAwarePipelineStage extends PipelineStage
{
    public function executeWithHeartbeat(
        PipelineContext $context,
        PipelineLeaseHeartbeat $heartbeat,
    ): PipelineStageResult;
}
