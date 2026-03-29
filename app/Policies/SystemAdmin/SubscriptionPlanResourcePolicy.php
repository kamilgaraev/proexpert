<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\SubscriptionPlan;
use App\Models\SystemAdmin;

class SubscriptionPlanResourcePolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.subscription_plans.view');
    }

    public function view(SystemAdmin $systemAdmin, SubscriptionPlan $subscriptionPlan): bool
    {
        return $this->allows($systemAdmin, 'system_admin.subscription_plans.view');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.subscription_plans.create');
    }

    public function update(SystemAdmin $systemAdmin, SubscriptionPlan $subscriptionPlan): bool
    {
        return $this->allows($systemAdmin, 'system_admin.subscription_plans.update');
    }

    public function delete(SystemAdmin $systemAdmin, SubscriptionPlan $subscriptionPlan): bool
    {
        return $this->allows($systemAdmin, 'system_admin.subscription_plans.delete');
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.subscription_plans.delete');
    }
}
