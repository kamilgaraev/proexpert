<?php

namespace App\Repositories\Landing;

use App\Models\SubscriptionAddon;
use App\Models\OrganizationSubscriptionAddon;

class OrganizationSubscriptionAddonRepository
{
    public function getAllAddons()
    {
        return SubscriptionAddon::where('is_active', true)->orderBy('display_order')->get();
    }

    public function getOrganizationAddons($organizationSubscriptionId)
    {
        return OrganizationSubscriptionAddon::where('organization_subscription_id', $organizationSubscriptionId)->get();
    }

    public function attachAddon($organizationSubscriptionId, $addonId)
    {
        return OrganizationSubscriptionAddon::firstOrCreate([
            'organization_subscription_id' => $organizationSubscriptionId,
            'subscription_addon_id' => $addonId
        ], [
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }

    public function detachAddon($organizationSubscriptionId, $addonId)
    {
        return OrganizationSubscriptionAddon::where([
            'organization_subscription_id' => $organizationSubscriptionId,
            'subscription_addon_id' => $addonId
        ])->delete();
    }
} 