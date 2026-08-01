<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts;

interface LookaheadReadinessAuthorizer
{
    public function assertAllowed(int $actorId, string $permission, int $organizationId, int $projectId): void;
}
