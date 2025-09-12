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

    public function previewPlanChange($organizationId, $newPlanSlug): array
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

        $organization = Organization::findOrFail($organizationId);
        $now = Carbon::now();
        
        // Рассчитываем перерасчет БЕЗ выполнения операции
        $billingCalculation = $this->calculatePlanChange($currentSubscription, $newPlan, $now);
        
        // Проверяем баланс для доплаты через BalanceService
        $organizationBalance = $this->balanceService->getOrCreateOrganizationBalance($organization);
        $currentBalance = $organizationBalance->balance;
        $hasEnoughBalance = $billingCalculation['amount_to_charge'] <= $currentBalance;
        
        // Предварительные данные новой подписки
        $previewSubscription = [
            'plan_name' => $newPlan->name,
            'plan_description' => $newPlan->description,
            'price' => $newPlan->price,
            'currency' => $newPlan->currency,
            'duration_in_days' => $newPlan->duration_in_days,
            'starts_at' => $now->format('Y-m-d H:i:s'),
            'ends_at' => $now->copy()->addDays($newPlan->duration_in_days)->format('Y-m-d H:i:s'),
            'limits' => [
                'max_foremen' => $newPlan->max_foremen,
                'max_projects' => $newPlan->max_projects,
                'max_users' => $newPlan->max_users,
                'max_storage_gb' => $newPlan->max_storage_gb,
            ]
        ];

        return [
            'success' => true,
            'preview' => [
                'current_subscription' => [
                    'plan_name' => $currentPlan->name,
                    'ends_at' => $currentSubscription->ends_at->format('Y-m-d H:i:s'),
                    'price' => $currentPlan->price
                ],
                'new_subscription' => $previewSubscription,
                'billing_calculation' => $billingCalculation,
                'balance_check' => [
                    'current_balance' => $currentBalance / 100, // в рублях
                    'required_amount' => $billingCalculation['amount_to_charge'] / 100,
                    'has_enough_balance' => $hasEnoughBalance,
                    'balance_after_change' => ($currentBalance + 
                        ($billingCalculation['amount_to_refund'] - $billingCalculation['amount_to_charge'])) / 100
                ],
                'can_proceed' => $hasEnoughBalance
            ],
            'message' => $hasEnoughBalance 
                ? $this->getChangeMessage($billingCalculation, $currentPlan->name, $newPlan->name)
                : 'Недостаточно средств на балансе. Требуется доплата: ' . abs($billingCalculation['difference']) . ' руб.'
        ];
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
        $remainingDays = max(0, round($remainingDays, 1)); // Не может быть отрицательным, округляем до 1 знака
        
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
            return "Тарифный план изменен с '{$currentPlanName}' на '{$newPlanName}'. Списано с баланса: " . abs($billingInfo['difference']) . " руб.";
        } elseif ($billingInfo['amount_to_refund'] > 0) {
            return "Тарифный план изменен с '{$currentPlanName}' на '{$newPlanName}'. Возвращено на баланс: " . abs($billingInfo['difference']) . " руб.";
        } else {
            return "Тарифный план изменен с '{$currentPlanName}' на '{$newPlanName}'. Стоимость не изменилась.";
        }
    }
} 