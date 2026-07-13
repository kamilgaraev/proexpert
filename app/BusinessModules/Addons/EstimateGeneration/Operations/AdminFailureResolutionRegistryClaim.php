<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminFailureResolutionRegistryClaim
{
    /** @param array<string, mixed>|null $result */
    private function __construct(
        public string $decision,
        public ?array $result = null,
    ) {}

    /** @param array<string, mixed>|null $result */
    public static function decide(
        string $commandFingerprint,
        string $storedFingerprint,
        string $status,
        ?array $result,
        bool $owner,
    ): self {
        if (! hash_equals($storedFingerprint, $commandFingerprint)) {
            return new self('conflict');
        }
        if ($status === 'completed' && is_array($result)) {
            return new self('replay', $result);
        }
        if ($status !== 'pending') {
            return new self('conflict');
        }

        return new self($owner ? 'execute' : 'pending');
    }
}
