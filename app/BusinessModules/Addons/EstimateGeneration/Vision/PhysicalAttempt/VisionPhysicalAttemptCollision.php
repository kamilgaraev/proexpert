<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt;

use RuntimeException;

final class VisionPhysicalAttemptCollision extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('vision_physical_attempt_identity_collision');
    }
}
