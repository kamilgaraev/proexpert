<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

interface TargetedPackageRebuildReviewer
{
    /** @return array<string, mixed> */
    public function review(array $draft, ?ArbiterOperationContext $operation = null): array;
}
