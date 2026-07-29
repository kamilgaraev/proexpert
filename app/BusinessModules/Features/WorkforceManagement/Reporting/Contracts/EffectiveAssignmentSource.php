<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts;

interface EffectiveAssignmentSource
{
    public function forEmployee(int $organizationId, int $employeeId): array;
}
