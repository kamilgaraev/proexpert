<?php

namespace App\Services\Landing;

use App\Repositories\Landing\OrganizationSubscriptionRepository;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

class OrganizationSubscriptionService
{
    protected $repo;

    public function __construct()
    {
        $this->repo = new OrganizationSubscriptionRepository();
    }

    public function getCurrentSubscription($organizationId)
    {
        return $this->repo->getByOrganizationId($organizationId);
    }

    public function subscribe($organizationId, $planSlug)
    {
        $plan = SubscriptionPlan::where('slug', $planSlug)->where('is_active', true)->firstOrFail();
        $now = Carbon::now();
        $data = [
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays($plan->duration_in_days),
        ];
        return $this->repo->createOrUpdate($organizationId, $plan->id, $data);
    }

    public function updateSubscription($organizationId, $planSlug)
    {
        // Апгрейд/даунгрейд: смена тарифа, перерасчёт дат
        $plan = SubscriptionPlan::where('slug', $planSlug)->where('is_active', true)->firstOrFail();
        $now = Carbon::now();
        $data = [
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays($plan->duration_in_days),
        ];
        return $this->repo->createOrUpdate($organizationId, $plan->id, $data);
    }
} 