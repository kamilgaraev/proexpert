<?php

namespace App\BusinessModules\Core\MultiOrganization\Contracts;

use Illuminate\Support\Collection;

interface ContractorSharingInterface
{
    public function availableQuery(int $organizationId): \Illuminate\Database\Eloquent\Builder;

    public function getAvailableContractors(int $organizationId): Collection;

    public function canUseContractor(int $contractorId, int $organizationId): bool;
}

