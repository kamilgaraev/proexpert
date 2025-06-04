<?php

namespace App\Repositories\Landing;

use App\Models\OrganizationSubscription;

class OrganizationSubscriptionRepository
{
    public function getByOrganizationId($organizationId)
    {
        return OrganizationSubscription::where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->first();
    }

    public function createOrUpdate($organizationId, $planId, array $data)
    {
        $subscription = OrganizationSubscription::where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->first();
        if ($subscription) {
            $subscription->update(array_merge([
                'subscription_plan_id' => $planId
            ], $data));
            return $subscription;
        }
        return OrganizationSubscription::create(array_merge([
            'organization_id' => $organizationId,
            'subscription_plan_id' => $planId
        ], $data));
    }
} 