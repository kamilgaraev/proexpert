<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityPolicySource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class EloquentWorkforceCapacityPolicySource implements WorkforceCapacityPolicySource
{
    public function forOrganization(int $organizationId): WorkforceCapacityPolicyDefinition
    {
        $timezone = DB::table('organizations')->where('id', $organizationId)->value('workforce_timezone');
        if (! is_string($timezone) || trim($timezone) === '') {
            throw new InvalidArgumentException('workforce_capacity_organization_timezone_missing');
        }

        return WorkforceCapacityPolicyDefinition::v1($timezone);
    }
}
