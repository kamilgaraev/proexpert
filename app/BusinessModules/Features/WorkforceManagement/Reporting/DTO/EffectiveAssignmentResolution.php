<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\DTO;

final readonly class EffectiveAssignmentResolution
{
    public function __construct(
        public array $assignments,
        public int $discardedDuplicateCount,
    ) {
    }
}
