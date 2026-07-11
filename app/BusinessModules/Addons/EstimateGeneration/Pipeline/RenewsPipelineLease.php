<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;

trait RenewsPipelineLease
{
    public function executeWithHeartbeat(
        PipelineContext $context,
        PipelineLeaseHeartbeat $heartbeat,
    ): PipelineStageResult {
        self::renewLease($heartbeat);
        $result = $this->execute($context);
        self::renewLease($heartbeat);

        return $result;
    }

    final protected static function renewLease(PipelineLeaseHeartbeat $heartbeat): void
    {
        if (! $heartbeat->renew()) {
            throw new PipelineStageException(FailureCategory::Recoverable, 'pipeline_claim_lost');
        }
    }
}
