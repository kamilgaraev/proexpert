<?php

namespace App\Services\Landing;

use App\Repositories\Landing\OrganizationSubscriptionRepository;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use App\Services\Billing\BalanceService;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Services\Logging\LoggingService;
use App\Models\Organization;
use App\Exceptions\Billing\InsufficientBalanceException;
use App\Services\SubscriptionModuleSyncService;
use App\Services\Billing\SubscriptionLimitsService;

class OrganizationSubscriptionService
{
    protected $repo;
    protected BalanceServiceInterface $balanceService;
    protected LoggingService $logging;
    protected SubscriptionModuleSyncService $moduleSyncService;
    protected SubscriptionLimitsService $limitsService;

    public function __construct(
        LoggingService $logging,
        SubscriptionModuleSyncService $moduleSyncService,
        SubscriptionLimitsService $limitsService
    ) {
        $this->repo = new OrganizationSubscriptionRepository();
        $this->balanceService = app(BalanceServiceInterface::class);
        $this->logging = $logging;
        $this->moduleSyncService = $moduleSyncService;
        $this->limitsService = $limitsService;
    }

    public function getCurrentSubscription($organizationId)
    {
        return $this->repo->getByOrganizationId($organizationId);
    }

    public function subscribe($organizationId, $planSlug, bool $isAutoPaymentEnabled = true, int $durationDays = 30)
    {
        $plan = SubscriptionPlan::where('slug', $planSlug)->where('is_active', true)->firstOrFail();
        $organization = Organization::findOrFail($organizationId);
        $now = Carbon::now();
        
        $finalPrice = $this->calculatePriceWithDuration($plan->price, $durationDays);
        
        $data = [
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays($durationDays),
            'next_billing_at' => $now->copy()->addDays($durationDays),
            'is_auto_payment_enabled' => $isAutoPaymentEnabled,
        ];

        // BUSINESS: Начало подписки - критически важная SaaS метрика
        $this->logging->business('subscription.creation.started', [
            'organization_id' => $organizationId,
            'plan_slug' => $planSlug,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_price' => $plan->price,
            'duration_days' => $durationDays,
            'final_price' => $finalPrice,
            'discount_applied' => $this->getDiscountRate($durationDays),
            'auto_payment_enabled' => $isAutoPaymentEnabled,
            'starts_at' => $now->toISOString(),
            'ends_at' => $now->copy()->addDays($durationDays)->toISOString(),
            'user_id' => Auth::id()
        ]);

        try {
            // Списываем стоимость плана с баланса (в копейках/центах)
            if (((float) $finalPrice) > 0) {
                $amountCents = (int) round(((float) $finalPrice) * 100);
                // Бросит InsufficientBalanceException, если средств нет
                $this->balanceService->debitBalance(
                    $organization,
                    $amountCents,
                    "Оплата подписки на план '{$plan->name}'"
                );
            }

            $subscription = $this->repo->createOrUpdate($organizationId, $plan->id, $data);

            // BUSINESS: Подписка успешно создана
            $this->logging->business('subscription.created', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'plan_slug' => $planSlug,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'amount_charged_cents' => ((float) $plan->price) > 0 ? $amountCents : 0,
                'amount_charged_rubles' => $plan->price,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'is_paid_plan' => ((float) $plan->price) > 0,
                'user_id' => Auth::id()
            ]);

            // AUDIT: Создание подписки для compliance
            $this->logging->audit('subscription.created', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'transaction_type' => 'subscription_created',
                'performed_by' => Auth::id() ?? 'system'
            ]);

            $moduleSyncResult = $this->moduleSyncService->syncModulesOnSubscribe($subscription);
            
            if ($moduleSyncResult['activated_count'] > 0 || $moduleSyncResult['converted_count'] > 0) {
                $this->logging->business('subscription.modules.synced', [
                    'subscription_id' => $subscription->id,
                    'organization_id' => $organizationId,
                    'activated_count' => $moduleSyncResult['activated_count'],
                    'converted_count' => $moduleSyncResult['converted_count'],
                    'modules' => $moduleSyncResult['modules']
                ]);
            }

            $this->limitsService->clearOrganizationSubscriptionCache($organizationId);

            return $subscription;

        } catch (InsufficientBalanceException $e) {
            // BUSINESS: Неудачная попытка подписки из-за недостатка средств  
            $this->logging->business('subscription.creation.failed.insufficient_balance', [
                'organization_id' => $organizationId,
                'plan_slug' => $planSlug,
                'plan_price' => $plan->price,
                'required_amount_cents' => (int) round(((float) $plan->price) * 100),
                'failure_reason' => 'insufficient_balance',
                'user_id' => Auth::id()
            ], 'warning');
            
            throw $e;
        }
    }

    protected function calculatePriceWithDuration(float $basePrice, int $durationDays): float
    {
        $monthlyPrice = $basePrice;
        
        return match($durationDays) {
            30 => $monthlyPrice,
            90 => $monthlyPrice * 3 * 0.95,
            365 => $monthlyPrice * 12 * 0.85,
            default => $monthlyPrice * ($durationDays / 30)
        };
    }

    protected function getDiscountRate(int $durationDays): float
    {
        return match($durationDays) {
            30 => 0,
            90 => 5,
            365 => 15,
            default => 0
        };
    }

    public function updateSubscription($organizationId, $planSlug, bool $isAutoPaymentEnabled = true)
    {
        // Получаем текущую подписку для сравнения
        $currentSubscription = $this->repo->getByOrganizationId($organizationId);
        
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

        // BUSINESS: Начало изменения подписки
        $this->logging->business('subscription.update.started', [
            'organization_id' => $organizationId,
            'old_plan_id' => $currentSubscription?->subscription_plan_id,
            'new_plan_slug' => $planSlug,
            'new_plan_id' => $plan->id,
            'new_plan_name' => $plan->name,
            'new_plan_price' => $plan->price,
            'change_type' => $this->getSubscriptionChangeType($currentSubscription, $plan),
            'user_id' => Auth::id()
        ]);

        try {
            if (((float) $plan->price) > 0) {
                $amountCents = (int) round(((float) $plan->price) * 100);
                $this->balanceService->debitBalance(
                    $organization,
                    $amountCents,
                    "Оплата смены подписки на план '{$plan->name}'"
                );
            }

            $subscription = $this->repo->createOrUpdate($organizationId, $plan->id, $data);

            // BUSINESS: Подписка успешно обновлена
            $this->logging->business('subscription.updated', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'old_plan_id' => $currentSubscription?->subscription_plan_id,
                'new_plan_id' => $plan->id,
                'new_plan_slug' => $planSlug,
                'amount_charged_cents' => ((float) $plan->price) > 0 ? $amountCents : 0,
                'amount_charged_rubles' => $plan->price,
                'change_type' => $this->getSubscriptionChangeType($currentSubscription, $plan),
                'user_id' => Auth::id()
            ]);

            // AUDIT: Изменение подписки
            $this->logging->audit('subscription.updated', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'old_plan_id' => $currentSubscription?->subscription_plan_id,
                'new_plan_id' => $plan->id,
                'transaction_type' => 'subscription_updated',
                'performed_by' => Auth::id() ?? 'system'
            ]);

            $this->limitsService->clearOrganizationSubscriptionCache($organizationId);

            return $subscription;

        } catch (InsufficientBalanceException $e) {
            // BUSINESS: Неудачная попытка обновления подписки
            $this->logging->business('subscription.update.failed.insufficient_balance', [
                'organization_id' => $organizationId,
                'new_plan_slug' => $planSlug,
                'new_plan_price' => $plan->price,
                'required_amount_cents' => (int) round(((float) $plan->price) * 100),
                'failure_reason' => 'insufficient_balance',
                'user_id' => Auth::id()
            ], 'warning');
            
            throw $e;
        }
    }

    public function cancelSubscription($organizationId): array
    {
        $subscription = $this->repo->getByOrganizationId($organizationId);
        
        if (!$subscription) {
            // BUSINESS: Попытка отменить несуществующую подписку
            $this->logging->business('subscription.cancellation.failed.not_found', [
                'organization_id' => $organizationId,
                'failure_reason' => 'subscription_not_found',
                'user_id' => Auth::id()
            ], 'warning');
            
            return [
                'success' => false,
                'message' => 'Активная подписка не найдена',
                'status_code' => 404
            ];
        }

        if ($subscription->canceled_at) {
            // BUSINESS: Попытка отменить уже отменённую подписку
            $this->logging->business('subscription.cancellation.failed.already_canceled', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'canceled_at' => $subscription->canceled_at,
                'failure_reason' => 'already_canceled',
                'user_id' => Auth::id()
            ], 'info');
            
            return [
                'success' => false,
                'message' => 'Подписка уже отменена',
                'status_code' => 400
            ];
        }

        // BUSINESS: Начало отмены подписки - важная SaaS метрика (churn)
        $this->logging->business('subscription.cancellation.started', [
            'organization_id' => $organizationId,
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->subscription_plan_id,
            'ends_at' => $subscription->ends_at,
            'days_remaining' => now()->diffInDays($subscription->ends_at),
            'user_id' => Auth::id()
        ]);

        // Отменяем подписку, но она продолжает работать до ends_at
        $subscription->update([
            'canceled_at' => Carbon::now(),
            'is_auto_payment_enabled' => false // Отключаем автопродление
        ]);

        // BUSINESS: Подписка успешно отменена - критично для churn анализа
        $this->logging->business('subscription.canceled', [
            'organization_id' => $organizationId,
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->subscription_plan_id,
            'canceled_at' => $subscription->canceled_at,
            'remains_active_until' => $subscription->ends_at,
            'days_remaining' => now()->diffInDays($subscription->ends_at),
            'user_id' => Auth::id(),
            'churn_event' => true
        ]);

        // AUDIT: Отмена подписки
        $this->logging->audit('subscription.canceled', [
            'organization_id' => $organizationId,
            'subscription_id' => $subscription->id,
            'transaction_type' => 'subscription_canceled',
            'performed_by' => Auth::id() ?? 'system'
        ]);

        $deactivatedModules = $this->moduleSyncService->handleSubscriptionCancellation($subscription);
        
        if ($deactivatedModules > 0) {
            $this->logging->business('subscription.modules.deactivated', [
                'subscription_id' => $subscription->id,
                'organization_id' => $organizationId,
                'deactivated_count' => $deactivatedModules,
                'reason' => 'subscription_canceled'
            ]);
        }

        $this->limitsService->clearOrganizationSubscriptionCache($organizationId);

        return [
            'success' => true,
            'subscription' => $subscription->fresh(),
            'message' => 'Подписка отменена, но будет действовать до ' . $subscription->ends_at->format('d.m.Y'),
            'deactivated_modules_count' => $deactivatedModules
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
        $oldPlan = $currentSubscription->plan;
        $updatedSubscription = $this->repo->createOrUpdate($organizationId, $newPlan->id, [
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays($newPlan->duration_in_days),
            'next_billing_at' => $now->copy()->addDays($newPlan->duration_in_days),
            'is_auto_payment_enabled' => $currentSubscription->is_auto_payment_enabled,
            'canceled_at' => null
        ]);

        $moduleSyncResult = $this->moduleSyncService->syncModulesOnPlanChange(
            $updatedSubscription,
            $oldPlan,
            $newPlan
        );
        
        if ($moduleSyncResult['deactivated_count'] > 0 || 
            $moduleSyncResult['activated_count'] > 0 || 
            $moduleSyncResult['converted_count'] > 0) {
            
            $this->logging->business('subscription.plan_change.modules_synced', [
                'subscription_id' => $updatedSubscription->id,
                'organization_id' => $organizationId,
                'old_plan' => $oldPlan->slug,
                'new_plan' => $newPlan->slug,
                'deactivated_count' => $moduleSyncResult['deactivated_count'],
                'activated_count' => $moduleSyncResult['activated_count'],
                'converted_count' => $moduleSyncResult['converted_count']
            ]);
        }

        $this->limitsService->clearOrganizationSubscriptionCache($organizationId);

        return [
            'success' => true,
            'subscription' => $updatedSubscription->load('plan'),
            'billing_info' => $billingCalculation,
            'modules_sync' => $moduleSyncResult,
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

    /**
     * Определяет тип изменения подписки для логирования
     */
    private function getSubscriptionChangeType($currentSubscription, $newPlan): string
    {
        if (!$currentSubscription || !$currentSubscription->plan) {
            return 'new_subscription';
        }

        $currentPrice = (float) $currentSubscription->plan->price;
        $newPrice = (float) $newPlan->price;

        if ($newPrice > $currentPrice) {
            return 'upgrade';
        } elseif ($newPrice < $currentPrice) {
            return 'downgrade';
        } else {
            return 'same_price_change';
        }
    }

    public function renewSubscription($organizationId): array
    {
        $subscription = $this->repo->getByOrganizationId($organizationId);
        
        if (!$subscription) {
            return [
                'success' => false,
                'message' => 'Подписка не найдена',
                'reason' => 'not_found'
            ];
        }

        if (!$subscription->is_auto_payment_enabled) {
            $this->logging->business('subscription.renewal.skipped.auto_payment_disabled', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
            ], 'info');
            
            return [
                'success' => false,
                'message' => 'Автопродление отключено',
                'reason' => 'auto_payment_disabled'
            ];
        }

        if ($subscription->isCanceled()) {
            $this->logging->business('subscription.renewal.skipped.canceled', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'canceled_at' => $subscription->canceled_at,
            ], 'info');
            
            return [
                'success' => false,
                'message' => 'Подписка отменена',
                'reason' => 'canceled'
            ];
        }

        if ($subscription->ends_at > now()->addDay()) {
            return [
                'success' => false,
                'message' => 'Подписка еще не заканчивается',
                'reason' => 'not_expiring'
            ];
        }

        $plan = $subscription->plan;
        if (!$plan || !$plan->is_active) {
            $this->logging->business('subscription.renewal.failed.plan_inactive', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'plan_id' => $subscription->subscription_plan_id,
            ], 'error');
            
            return [
                'success' => false,
                'message' => 'Тарифный план неактивен',
                'reason' => 'plan_inactive'
            ];
        }

        $organization = Organization::findOrFail($organizationId);
        $now = Carbon::now();
        $durationDays = $plan->duration_in_days ?? 30;
        
        $this->logging->business('subscription.renewal.started', [
            'organization_id' => $organizationId,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'plan_slug' => $plan->slug,
            'plan_price' => $plan->price,
            'duration_days' => $durationDays,
            'old_ends_at' => $subscription->ends_at,
        ]);

        try {
            if (((float) $plan->price) > 0) {
                $amountCents = (int) round(((float) $plan->price) * 100);
                
                $this->balanceService->debitBalance(
                    $organization,
                    $amountCents,
                    "Автопродление подписки '{$plan->name}'"
                );
            }

            $subscription->update([
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => $now->copy()->addDays($durationDays),
                'next_billing_at' => $now->copy()->addDays($durationDays),
                'canceled_at' => null,
            ]);

            $this->logging->business('subscription.renewed', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'amount_charged_cents' => ((float) $plan->price) > 0 ? $amountCents : 0,
                'amount_charged_rubles' => $plan->price,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'renewal_type' => 'automatic',
            ]);

            $this->logging->audit('subscription.renewed', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'transaction_type' => 'subscription_renewed',
                'performed_by' => 'system'
            ]);

            $updatedCount = $this->moduleSyncService->syncModulesOnRenew($subscription);
            
            if ($updatedCount > 0) {
                $this->logging->business('subscription.renewal.modules_synced', [
                    'subscription_id' => $subscription->id,
                    'organization_id' => $organizationId,
                    'updated_modules_count' => $updatedCount,
                ]);
            }

            $this->limitsService->clearOrganizationSubscriptionCache($organizationId);

            return [
                'success' => true,
                'subscription' => $subscription->fresh(),
                'message' => 'Подписка успешно продлена до ' . $subscription->ends_at->format('d.m.Y'),
            ];

        } catch (InsufficientBalanceException $e) {
            $subscription->update([
                'status' => 'expired',
                'is_auto_payment_enabled' => false,
            ]);

            $this->logging->business('subscription.renewal.failed.insufficient_balance', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'plan_slug' => $plan->slug,
                'plan_price' => $plan->price,
                'required_amount_cents' => (int) round(((float) $plan->price) * 100),
                'failure_reason' => 'insufficient_balance',
            ], 'warning');
            
            $this->limitsService->clearOrganizationSubscriptionCache($organizationId);
            
            return [
                'success' => false,
                'message' => 'Недостаточно средств для автопродления. Подписка истекла.',
                'reason' => 'insufficient_balance',
                'required_amount' => $plan->price,
            ];
            
        } catch (\Exception $e) {
            $this->logging->business('subscription.renewal.failed.error', [
                'organization_id' => $organizationId,
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ], 'error');
            
            return [
                'success' => false,
                'message' => 'Ошибка при продлении подписки: ' . $e->getMessage(),
                'reason' => 'error',
            ];
        }
    }
} 