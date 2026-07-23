<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use InvalidArgumentException;

final readonly class TargetedPackageCommitResult
{
    public function __construct(
        public int $sessionId,
        public string $packageKey,
        public string $outcome,
        public int $stateVersion,
        public bool $replayed,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromOperation(array $payload, bool $replayed): self
    {
        if (! is_int($payload['session_id'] ?? null)
            || ! is_string($payload['package_key'] ?? null)
            || ! is_string($payload['outcome'] ?? null)
            || ! is_int($payload['state_version'] ?? null)) {
            throw new InvalidArgumentException('Targeted package operation audit is invalid.');
        }

        return new self(
            $payload['session_id'],
            $payload['package_key'],
            $payload['outcome'],
            $payload['state_version'],
            $replayed,
        );
    }
}
