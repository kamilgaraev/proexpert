<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\AuthorizationDecision;

interface LookaheadReadinessAuthorizer
{
    public function authorize(int $actorId, string $permission, int $organizationId, int $projectId): AuthorizationDecision;
}
