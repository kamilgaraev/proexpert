<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;

interface WorkforceCapacityPolicySource
{
    public function forOrganization(int $organizationId): WorkforceCapacityPolicyDefinition;
}
