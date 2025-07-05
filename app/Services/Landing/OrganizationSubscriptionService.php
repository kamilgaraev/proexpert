<?php

namespace App\Services\Landing;

use App\Repositories\Landing\OrganizationSubscriptionRepository;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use App\Services\Billing\BalanceService;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Models\Organization;
use App\Exceptions\Billing\InsufficientBalanceException;

class OrganizationSubscriptionService
{
    protected $repo;
    protected BalanceServiceInterface $balanceService;

    public function __construct()
    {
        $this->repo = new OrganizationSubscriptionRepository();
        $this->balanceService = app(BalanceServiceInterface::class);
    }

    public function getCurrentSubscription($organizationId)
    {
        return $this->repo->getByOrganizationId($organizationId);
    }

    public function subscribe($organizationId, $planSlug)
    {
        $plan = SubscriptionPlan::where('slug', $planSlug)->where('is_active', true)->firstOrFail();
        $organization = Organization::findOrFail($organizationId);
        $now = Carbon::now();
        $data = [
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays($plan->duration_in_days),
        ];

        // Списываем стоимость плана с баланса (в копейках/центах)
        if (((float) $plan->price) > 0) {
            $amountCents = (int) round(((float) $plan->price) * 100);
            // Бросит InsufficientBalanceException, если средств нет
            $this->balanceService->debitBalance(
                $organization,
                $amountCents,
                "Оплата подписки на план '{$plan->name}'"
            );
        }

        return $this->repo->createOrUpdate($organizationId, $plan->id, $data);
    }

    public function updateSubscription($organizationId, $planSlug)
    {
        // Апгрейд/даунгрейд: смена тарифа, перерасчёт дат
        $plan = SubscriptionPlan::where('slug', $planSlug)->where('is_active', true)->firstOrFail();
        $organization = Organization::findOrFail($organizationId);
        $now = Carbon::now();
        $data = [
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays($plan->duration_in_days),
        ];

        if (((float) $plan->price) > 0) {
            $amountCents = (int) round(((float) $plan->price) * 100);
            $this->balanceService->debitBalance(
                $organization,
                $amountCents,
                "Оплата смены подписки на план '{$plan->name}'"
            );
        }

        return $this->repo->createOrUpdate($organizationId, $plan->id, $data);
    }
} 