<?php

namespace App\Interfaces\Billing;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Collection;

interface SubscriptionPlanServiceInterface
{
    /**
     * Получить все активные тарифные планы.
     *
     * @return Collection<SubscriptionPlan>
     */
    public function getActivePlans(): Collection;

    /**
     * Найти тарифный план по его slug.
     *
     * @param string $slug
     * @return SubscriptionPlan|null
     */
    public function findBySlug(string $slug): ?SubscriptionPlan;

    /**
     * Найти тарифный план по ID.
     *
     * @param int $id
     * @return SubscriptionPlan|null
     */
    public function findById(int $id): ?SubscriptionPlan;
} 