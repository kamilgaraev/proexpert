<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class SessionActionResult
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public EstimateGenerationSession $session,
        public bool $successful,
        public string $messageKey,
        public int $httpStatus,
        public array $context = [],
    ) {}
}
