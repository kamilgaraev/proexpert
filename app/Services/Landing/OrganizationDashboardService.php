<?php

namespace App\Services\Landing;

use App\Services\Landing\OrganizationSubscriptionService;

class OrganizationDashboardService
{
    protected OrganizationSubscriptionService $subscriptionService;

    public function __construct(OrganizationSubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    public function getDashboardData($organization)
    {

        $subscription = $this->subscriptionService->getCurrentSubscription($organization->id);
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

        // Add-on'ы временно отключены
        $addons = collect([]);

        return [
            'plan' => $planData,
            'addons' => $addons
        ];
    }
} 