<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\Models\SystemAdmin;
use App\Models\User;

final readonly class RetryEstimateGenerationSessionCommand
{
    public function __construct(
        public int $sessionId,
        public int $organizationId,
        public int $projectId,
        public int $expectedStateVersion,
        public User|SystemAdmin|null $actor = null,
    ) {}
}
