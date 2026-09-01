<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Contracts;

use DateTimeInterface;

interface WorkforcePersonNameProvider
{
    public function employeeNameAt(int $organizationId, int $userId, DateTimeInterface $date): ?string;
}
