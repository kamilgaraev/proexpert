<?php

namespace App\Services\Billing;

use App\Interfaces\Billing\SubscriptionPlanServiceInterface;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class SubscriptionPlanService implements SubscriptionPlanServiceInterface
{
    public function getActivePlans(): Collection
    {
        // Кешируем список планов, т.к. он меняется нечасто
        return Cache::remember('active_subscription_plans', now()->addHours(24), function () {
            return SubscriptionPlan::active()->get();
        });
    }

    public function findBySlug(string $slug): ?SubscriptionPlan
    {
        // Можно также добавить кеширование для отдельных планов, если будет много запросов
        return SubscriptionPlan::where('slug', $slug)->where('is_active', true)->first();
    }

    public function findById(int $id): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('id', $id)->where('is_active', true)->first();
    }
} 