<?php

namespace App\Services\Landing;

use App\Repositories\Landing\OrganizationOneTimePurchaseRepository;
use Illuminate\Support\Facades\Auth;

class OrganizationOneTimePurchaseService
{
    protected $repo;

    public function __construct()
    {
        $this->repo = new OrganizationOneTimePurchaseRepository();
    }

    public function create($organizationId, $userId, $type, $description, $amount, $currency = 'RUB')
    {
        return $this->repo->create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'type' => $type,
            'description' => $description,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'paid',
            'purchased_at' => now(),
        ]);
    }

    public function getHistory($organizationId)
    {
        return $this->repo->getByOrganizationId($organizationId);
    }
} 