<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\Models\Contractor;
use Illuminate\Support\Collection;

class SingleContractorAccess implements ContractorSharingInterface
{
    public function getAvailableContractors(int $organizationId): Collection
    {
        return Contractor::where('organization_id', $organizationId)->get();
    }

    public function canUseContractor(int $contractorId, int $organizationId): bool
    {
        $contractor = Contractor::find($contractorId);
        return $contractor && $contractor->organization_id === $organizationId;
    }
}

