<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

interface TargetedPackageRebuildCommitter
{
    /** @param array<string, mixed> $reviewedDraft */
    public function commit(
        TargetedPackageRebuildCommand $command,
        TargetedPackagePatchResult $result,
        array $reviewedDraft,
    ): TargetedPackageCommitResult;
}
