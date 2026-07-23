<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

final readonly class TargetedPackageRebuildOperationStoreResult
{
    public function __construct(
        public TargetedPackageRebuildOperationData $operation,
        public bool $created,
    ) {}
}
