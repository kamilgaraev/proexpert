<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use DateTimeImmutable;

interface TargetedPackageRebuildOperationStore
{
    public function createOrFind(TargetedPackageRebuildOperationData $operation): TargetedPackageRebuildOperationStoreResult;

    public function find(string $operationId): ?TargetedPackageRebuildOperationData;

    public function claimQueued(string $operationId, string $leaseToken, DateTimeImmutable $leaseExpiresAt): ?TargetedPackageRebuildOperationData;

    public function save(TargetedPackageRebuildOperationData $operation): void;
}
