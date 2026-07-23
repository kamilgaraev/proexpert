<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

final readonly class TargetedPackagePatchResult
{
    public function __construct(
        public array $draft,
        public string $packageKey,
        public string $targetBeforeFingerprint,
        public string $targetAfterFingerprint,
        public array $nonTargetFingerprints,
    ) {
    }
}
