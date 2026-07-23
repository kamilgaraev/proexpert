<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use InvalidArgumentException;

final readonly class TargetedPackageRebuildSessionSnapshot
{
    /** @param array<string, mixed> $draft */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $stateVersion,
        public string $status,
        public ?int $appliedEstimateId,
        public array $draft,
    ) {
        if (min($organizationId, $projectId, $sessionId) < 1
            || $stateVersion < 0
            || preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $status) !== 1
            || ($appliedEstimateId !== null && $appliedEstimateId < 1)) {
            throw new InvalidArgumentException('Invalid targeted rebuild session snapshot.');
        }
    }
}
