<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting\Contracts;

interface EffectiveLaborRateSource
{
    public function forEmployee(int $organizationId, int $employeeId): array;
}
