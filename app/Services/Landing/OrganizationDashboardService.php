<?php

namespace App\Services\Landing;

use App\Services\Landing\OrganizationSubscriptionService;
use App\Services\Landing\OrganizationSubscriptionAddonService;

class OrganizationDashboardService
{
    public function getDashboardData($organization)
    {
        $subscriptionService = new OrganizationSubscriptionService();
        $addonService = new OrganizationSubscriptionAddonService();

        $subscription = $subscriptionService->getCurrentSubscription($organization->id);
        $plan = $subscription ? $subscription->plan : null;

        // Остатки по лимитам
        $usedForemen = method_exists($organization, 'foremen') ? $organization->foremen()->count() : null;
        $usedProjects = method_exists($organization, 'projects') ? $organization->projects()->count() : null;
        $usedStorageGb = method_exists($organization, 'usedStorageGb') ? $organization->usedStorageGb() : null;

        $daysLeft = $subscription && $subscription->ends_at ? now()->diffInDays($subscription->ends_at, false) : null;

        $planData = $plan ? [
            'name' => $plan->name,
            'ends_at' => $subscription->ends_at,
            'days_left' => $daysLeft,
            'max_foremen' => $plan->max_foremen,
            'max_projects' => $plan->max_projects,
            'max_storage_gb' => $plan->max_storage_gb,
            'used_foremen' => $usedForemen,
            'used_projects' => $usedProjects,
            'used_storage_gb' => $usedStorageGb,
        ] : null;

        // Add-on'ы
        $orgAddons = $addonService->getOrganizationAddons($organization->id);
        $addons = collect($orgAddons)->map(function($addon) {
            return [
                'name' => $addon->addon->name ?? null,
                'status' => $addon->status,
                'expires_at' => $addon->expires_at,
            ];
        })->values();

        return [
            'plan' => $planData,
            'addons' => $addons
        ];
    }
} 