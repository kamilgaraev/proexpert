<?php

namespace App\Repositories\Landing;

use App\Models\OrganizationOneTimePurchase;

class OrganizationOneTimePurchaseRepository
{
    public function create($data)
    {
        return OrganizationOneTimePurchase::create($data);
    }

    public function getByOrganizationId($organizationId)
    {
        return OrganizationOneTimePurchase::where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->get();
    }
} 