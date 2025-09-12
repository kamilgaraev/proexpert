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

    public function subscribe($organizationId, $planSlug, bool $isAutoPaymentEnabled = true)
    {
        $plan = SubscriptionPlan::where('slug', $planSlug)->where('is_active', true)->firstOrFail();
        $organization = Organization::findOrFail($organizationId);
        $now = Carbon::now();
        $data = [
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays($plan->duration_in_days),
            'is_auto_payment_enabled' => $isAutoPaymentEnabled,
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

    public function updateSubscription($organizationId, $planSlug, bool $isAutoPaymentEnabled = true)
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
            'is_auto_payment_enabled' => $isAutoPaymentEnabled,
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

    public function cancelSubscription($organizationId): array
    {
        $subscription = $this->repo->getByOrganizationId($organizationId);
        
        if (!$subscription) {
            return [
                'success' => false,
                'message' => 'Активная подписка не найдена',
                'status_code' => 404
            ];
        }

        if ($subscription->canceled_at) {
            return [
                'success' => false,
                'message' => 'Подписка уже отменена',
                'status_code' => 400
            ];
        }

        // Отменяем подписку, но она продолжает работать до ends_at
        $subscription->update([
            'canceled_at' => Carbon::now(),
            'is_auto_payment_enabled' => false // Отключаем автопродление
        ]);

        return [
            'success' => true,
            'subscription' => $subscription->fresh(),
            'message' => 'Подписка отменена, но будет действовать до ' . $subscription->ends_at->format('d.m.Y')
        ];
    }

    public function changePlan($organizationId, $newPlanSlug): array
    {
        $currentSubscription = $this->repo->getByOrganizationId($organizationId);
        
        if (!$currentSubscription || $currentSubscription->status !== 'active') {
            return [
                'success' => false,
                'message' => 'Активная подписка не найдена',
                'status_code' => 404
            ];
        }

        $newPlan = SubscriptionPlan::where('slug', $newPlanSlug)->where('is_active', true)->first();
        if (!$newPlan) {
            return [
                'success' => false,
                'message' => 'Тарифный план не найден',
                'status_code' => 404
            ];
        }

        $currentPlan = $currentSubscription->plan;
        if ($currentPlan->id === $newPlan->id) {
            return [
                'success' => false,
                'message' => 'Вы уже используете этот тарифный план',
                'status_code' => 400
            ];
        }

        return $this->performPlanChange($currentSubscription, $newPlan, $organizationId);
    }

    private function performPlanChange($currentSubscription, $newPlan, $organizationId): array
    {
        $organization = Organization::findOrFail($organizationId);
        $now = Carbon::now();
        
        // Рассчитываем перерасчет
        $billingCalculation = $this->calculatePlanChange($currentSubscription, $newPlan, $now);
        
        // Если нужно доплатить
        if ($billingCalculation['amount_to_charge'] > 0) {
            try {
                $this->balanceService->debitBalance(
                    $organization,
                    $billingCalculation['amount_to_charge'],
                    "Доплата при смене тарифа с '{$currentSubscription->plan->name}' на '{$newPlan->name}'"
                );
            } catch (InsufficientBalanceException $e) {
                return [
                    'success' => false,
                    'message' => 'Недостаточно средств на балансе для смены тарифа. Требуется доплата: ' . 
                                ($billingCalculation['amount_to_charge'] / 100) . ' руб.',
                    'status_code' => 400,
                    'billing_info' => $billingCalculation
                ];
            }
        }
        
        // Если возвращаем деньги
        if ($billingCalculation['amount_to_refund'] > 0) {
            $this->balanceService->creditBalance(
                $organization,
                $billingCalculation['amount_to_refund'],
                "Возврат при смене тарифа с '{$currentSubscription->plan->name}' на '{$newPlan->name}'"
            );
        }

        // Обновляем подписку
        $updatedSubscription = $this->repo->createOrUpdate($organizationId, $newPlan->id, [
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays($newPlan->duration_in_days),
            'next_billing_at' => $now->copy()->addDays($newPlan->duration_in_days),
            'is_auto_payment_enabled' => $currentSubscription->is_auto_payment_enabled,
            'canceled_at' => null // Сбрасываем отмену, если была
        ]);

        return [
            'success' => true,
            'subscription' => $updatedSubscription->load('plan'),
            'billing_info' => $billingCalculation,
            'message' => $this->getChangeMessage($billingCalculation, $currentSubscription->plan->name, $newPlan->name)
        ];
    }

    private function calculatePlanChange($currentSubscription, $newPlan, $now): array
    {
        $currentPlan = $currentSubscription->plan;
        
        // Рассчитываем оставшиеся дни текущей подписки
        $remainingDays = $now->diffInDays($currentSubscription->ends_at, false);
        $remainingDays = max(0, $remainingDays); // Не может быть отрицательным
        
        // Стоимость оставшихся дней текущего плана
        $dailyCostCurrent = $currentPlan->price / $currentPlan->duration_in_days;
        $remainingValueCurrent = $dailyCostCurrent * $remainingDays;
        
        // Стоимость нового плана
        $newPlanCost = $newPlan->price;
        
        // Разница в копейках
        $difference = ($newPlanCost - $remainingValueCurrent) * 100;
        
        return [
            'current_plan' => $currentPlan->name,
            'new_plan' => $newPlan->name,
            'remaining_days' => $remainingDays,
            'remaining_value' => round($remainingValueCurrent, 2),
            'new_plan_cost' => $newPlan->price,
            'difference' => round($difference / 100, 2),
            'amount_to_charge' => $difference > 0 ? (int)$difference : 0,
            'amount_to_refund' => $difference < 0 ? (int)abs($difference) : 0,
        ];
    }

    private function getChangeMessage($billingInfo, $currentPlanName, $newPlanName): string
    {
        if ($billingInfo['amount_to_charge'] > 0) {
            return "Тарифный план изменен с '{$currentPlanName}' на '{$newPlanName}'. Списано с баланса: {$billingInfo['difference']} руб.";
        } elseif ($billingInfo['amount_to_refund'] > 0) {
            return "Тарифный план изменен с '{$currentPlanName}' на '{$newPlanName}'. Возвращено на баланс: {$billingInfo['difference']} руб.";
        } else {
            return "Тарифный план изменен с '{$currentPlanName}' на '{$newPlanName}'. Стоимость не изменилась.";
        }
    }
} 