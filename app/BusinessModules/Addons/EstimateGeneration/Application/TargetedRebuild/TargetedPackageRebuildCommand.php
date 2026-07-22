<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterVerdict;
use InvalidArgumentException;

final readonly class TargetedPackageRebuildCommand
{
    public function __construct(
        public int $sessionId,
        public int $organizationId,
        public int $projectId,
        public int $expectedStateVersion,
        public string $sourceInputVersion,
        public string $operationId,
        public string $arbiterInputHash,
        public string $packageKey,
        public ArbiterVerdict $verdict,
        public string $sessionStatus,
        public array $draft,
    ) {
        if ($sessionId <= 0 || $organizationId <= 0 || $projectId <= 0) {
            throw new InvalidArgumentException('Targeted rebuild identity values must be positive.');
        }
        if ($expectedStateVersion < 0) {
            throw new InvalidArgumentException('Targeted rebuild state version cannot be negative.');
        }
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $operationId) !== 1) {
            throw new InvalidArgumentException('Targeted rebuild operation identifier is invalid.');
        }
        if (! $this->isSha256($sourceInputVersion) || ! $this->isSha256($arbiterInputHash)) {
            throw new InvalidArgumentException('Targeted rebuild input hashes must be canonical sha256 values.');
        }
        if (preg_match('/\A[A-Za-z0-9:._-]{1,120}\z/', $packageKey) !== 1) {
            throw new InvalidArgumentException('Targeted rebuild package key is invalid.');
        }
        if (preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $sessionStatus) !== 1) {
            throw new InvalidArgumentException('Targeted rebuild session status is invalid.');
        }
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/\Asha256:[a-f0-9]{64}\z/', $value) === 1;
    }
}
