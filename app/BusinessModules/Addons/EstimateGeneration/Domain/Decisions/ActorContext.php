<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions;

use InvalidArgumentException;

final readonly class ActorContext
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $actorId,
        public string $idempotencyKey,
        public ?string $expectedSourceVersion = null,
        public ?string $expectedValueFingerprint = null,
    ) {
        if ($organizationId < 1 || $projectId < 1 || $actorId < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $idempotencyKey) !== 1
            || ($expectedSourceVersion !== null && preg_match('/^sha256:[a-f0-9]{64}$/', $expectedSourceVersion) !== 1)
            || ($expectedValueFingerprint !== null && preg_match('/^[a-f0-9]{64}$/', $expectedValueFingerprint) !== 1)) {
            throw new InvalidArgumentException('Estimate decision actor context is invalid.');
        }
    }
}
