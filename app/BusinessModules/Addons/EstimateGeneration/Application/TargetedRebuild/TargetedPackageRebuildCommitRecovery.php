<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use InvalidArgumentException;

final readonly class TargetedPackageRebuildCommitRecovery
{
    /**
     * @param  array<string, mixed>  $resultDelta
     * @param  array<string, mixed>  $safeArbiterReview
     */
    private function __construct(
        public string $operationId,
        public string $packageKey,
        public array $resultDelta,
        public array $safeArbiterReview,
    ) {
    }

    public static function fromReviewedOperation(TargetedPackageRebuildOperationData $operation): self
    {
        if ($operation->status !== 'reviewed') {
            throw new InvalidArgumentException('Targeted rebuild operation must remain reviewed for commit recovery.');
        }

        return new self(
            $operation->operationId,
            $operation->packageKey,
            $operation->resultDelta,
            $operation->safeArbiterReview,
        );
    }
}
