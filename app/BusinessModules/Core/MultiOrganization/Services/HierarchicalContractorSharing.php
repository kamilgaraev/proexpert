<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Support\Collection;

class HierarchicalContractorSharing implements ContractorSharingInterface
{
    public function getAvailableContractors(int $organizationId): Collection
    {
        $org = Organization::find($organizationId);
        if (!$org) {
            return Contractor::where('organization_id', $organizationId)->get();
        }

        $query = Contractor::where('organization_id', $organizationId);

        if ($org->parent_organization_id) {
            $parentContractors = Contractor::where('organization_id', $org->parent_organization_id);
            $query = $query->union($parentContractors);
        }

        return $query->get()->map(function($contractor) use ($org) {
            $contractor->is_inherited = $contractor->organization_id !== $org->id;
            $contractor->source = $contractor->is_inherited ? 'parent' : 'own';
            return $contractor;
        });
    }

    public function canUseContractor(int $contractorId, int $organizationId): bool
    {
        $contractor = Contractor::find($contractorId);
        if (!$contractor) {
            return false;
        }

        if ($contractor->organization_id === $organizationId) {
            return true;
        }

        $org = Organization::find($organizationId);
        if ($org && $org->parent_organization_id === $contractor->organization_id) {
            return true;
        }

        return false;
    }
}

