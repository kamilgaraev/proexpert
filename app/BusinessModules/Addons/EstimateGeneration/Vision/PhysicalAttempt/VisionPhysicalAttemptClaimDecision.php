<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt;

final readonly class VisionPhysicalAttemptClaimDecision
{
    public function __construct(
        public string $action,
        public ?string $ownerToken = null,
    ) {}
}
