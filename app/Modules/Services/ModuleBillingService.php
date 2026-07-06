<?php

namespace App\Modules\Services;

use App\Models\Organization;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationSubscription;
use App\Modules\Core\BillingEngine;
use App\Interfaces\Billing\BalanceServiceInterface;
use Illuminate\Support\Facades\Log;

class ModuleBillingService
{
    protected BillingEngine $billingEngine;
    protected BalanceServiceInterface $balanceService;
    
    public function __construct(BillingEngine $billingEngine, BalanceServiceInterface $balanceService)
    {
        $this->billingEngine = $billingEngine;
        $this->balanceService = $balanceService;
    }
    
    public function calculateModuleCost(string $moduleSlug, int $organizationId): array
    {
        $module = Module::where('slug', $moduleSlug)->first();
        
        if (!$module) {
            return [
                'success' => false,
                'message' => 'Модуль не найден'
            ];
        }
        
        $organization = Organization::findOrFail($organizationId);
        $cost = $this->billingEngine->calculateChargeAmount($module);
        $canAfford = $this->billingEngine->canAfford($organization, $module);
        $currentBalance = $this->billingEngine->getBalance($organization);
        
        return [
            'success' => true,
            'module' => [
                'name' => $module->name,
                'slug' => $module->slug,
                'billing_model' => $module->billing_model
            ],
            'cost' => [
                'amount' => $cost,
                'currency' => $module->getCurrency(),
                'duration_days' => $module->getDurationDays(),
                'is_free' => $module->isFree()
            ],
            'balance' => [
                'current' => $currentBalance,
                'after_purchase' => $currentBalance - $cost,
                'can_afford' => $canAfford
            ]
        ];
    }
    
    public function processRefund(int $organizationId, string $moduleSlug, ?string $reason = null): array
    {
        $module = Module::where('slug', $moduleSlug)->first();
        
        if (!$module) {
            return [
                'success' => false,
                'message' => 'Модуль не найден'
            ];
        }
        
        $activation = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('module_id', $module->id)
            ->first();
            
        if (!$activation) {
            return [
                'success' => false,
                'message' => 'Активация модуля не найдена'
            ];
        }
        
        $refundAmount = $this->billingEngine->calculateRefundAmount(
            $module,
            $activation->activated_at,
            $activation->expires_at
        );
        
        if ($refundAmount <= 0) {
            return [
                'success' => false,
                'message' => 'Возврат невозможен или сумма возврата равна нулю'
            ];
        }
        
        try {
            $organization = Organization::findOrFail($organizationId);
            $success = $this->billingEngine->refundModule(
                $organization,
                $module,
                $refundAmount,
                $reason ?: "Возврат за модуль '{$module->name}'"
            );
            
            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Возврат успешно выполнен',
                    'refund_amount' => $refundAmount,
                    'currency' => $module->getCurrency()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Ошибка выполнения возврата'
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('Module refund failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'refund_amount' => $refundAmount,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Ошибка возврата: ' . $e->getMessage()
            ];
        }
    }
    
    public function getBillingHistory(int $organizationId, ?string $moduleSlug = null): array
    {
        $query = OrganizationModuleActivation::with('module')
            ->where('organization_id', $organizationId);
            
        if ($moduleSlug) {
            $query->whereHas('module', function ($q) use ($moduleSlug) {
                $q->where('slug', $moduleSlug);
            });
        }
        
        $activations = $query->orderBy('created_at', 'desc')->get();
        
        $history = $activations->map(function ($activation) {
            return [
                'module_name' => $activation->module->name,
                'module_slug' => $activation->module->slug,
                'activated_at' => $activation->activated_at,
                'expires_at' => $activation->expires_at,
                'status' => $activation->status,
                'paid_amount' => $activation->paid_amount,
                'currency' => $activation->module->getCurrency(),
                'billing_model' => $activation->module->billing_model,
                'is_active' => $activation->isActive(),
                'days_until_expiration' => $activation->getDaysUntilExpiration()
            ];
        });
        
        $totalSpent = $activations->sum('paid_amount');
        $activeCount = $activations->where('status', 'active')->count();
        
        return [
            'success' => true,
            'history' => $history->toArray(),
            'summary' => [
                'total_activations' => $activations->count(),
                'active_modules' => $activeCount,
                'total_spent' => $totalSpent,
                'currency' => 'RUB'
            ]
        ];
    }
    
    public function getUpcomingBilling(int $organizationId, int $daysAhead = 30): array
    {
        $now = now();
        $billingUntil = $now->copy()->addDays($daysAhead);

        $upcomingBilling = OrganizationModuleActivation::with('module')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('is_bundled_with_plan', false)
            ->where('paid_amount', '>', 0)
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->whereNotNull('next_billing_date')
            ->whereBetween('next_billing_date', [$now, $billingUntil])
            ->orderBy('next_billing_date')
            ->get();
            
        $billing = $upcomingBilling->map(function ($activation) {
            return [
                'type' => 'module',
                'module_name' => $activation->module->name,
                'module_slug' => $activation->module->slug,
                'next_billing_date' => $activation->next_billing_date,
                'amount' => $activation->module->getPrice(),
                'currency' => $activation->module->getCurrency(),
                'days_until_billing' => now()->diffInDays($activation->next_billing_date, false)
            ];
        });

        $activeSubscription = $this->getActiveSubscription($organizationId);

        if (
            $activeSubscription !== null
            && $activeSubscription->plan !== null
            && $activeSubscription->next_billing_at !== null
            && $activeSubscription->next_billing_at->between($now, $billingUntil)
        ) {
            $billing->push([
                'type' => 'subscription',
                'subscription_id' => $activeSubscription->id,
                'plan_name' => $activeSubscription->plan->name,
                'plan_slug' => $activeSubscription->plan->slug,
                'next_billing_date' => $activeSubscription->next_billing_at,
                'amount' => (float) $activeSubscription->plan->price,
                'currency' => $activeSubscription->plan->currency ?? 'RUB',
                'days_until_billing' => $now->diffInDays($activeSubscription->next_billing_at, false),
            ]);
        }

        $billing = $billing->sortBy('next_billing_date')->values();
        
        $totalUpcoming = $billing->sum('amount');
        $organization = Organization::findOrFail($organizationId);
        $currentBalance = $this->billingEngine->getBalance($organization);
        
        return [
            'success' => true,
            'upcoming_billing' => $billing->toArray(),
            'summary' => [
                'total_upcoming' => $totalUpcoming,
                'current_balance' => $currentBalance,
                'balance_after_billing' => $currentBalance - $totalUpcoming,
                'sufficient_balance' => $currentBalance >= $totalUpcoming,
                'currency' => 'RUB'
            ]
        ];
    }
    
    public function getOrganizationBillingStats(int $organizationId): array
    {
        $activations = OrganizationModuleActivation::with('module')
            ->where('organization_id', $organizationId)
            ->get();
            
        $activeModules = $activations->filter(function ($activation) {
            return $activation->isActive();
        });
        $standaloneSubscriptionModules = $activeModules->filter(function ($activation) {
            return ! $activation->isBundled()
                && (float) $activation->paid_amount > 0
                && $activation->module !== null
                && $activation->module->billing_model === 'subscription';
        });
        $standaloneSubscriptionAmount = $standaloneSubscriptionModules->sum(function ($activation) {
            return $activation->module->getPrice();
        });

        $activeSubscription = $this->getActiveSubscription($organizationId);
        $activeSubscriptionAmount = $activeSubscription?->plan !== null
            ? (float) $activeSubscription->plan->price
            : 0.0;

        $monthlySpend = $activeSubscriptionAmount + $standaloneSubscriptionAmount;
        
        $oneTimeSpend = $activations->filter(function ($activation) {
            return ! $activation->isBundled()
                && $activation->module !== null
                && $activation->module->billing_model === 'one_time'
                && $activation->created_at->isCurrentMonth();
        })->sum('paid_amount');
        
        return [
            'success' => true,
            'stats' => [
                'active_modules' => $activeModules->count(),
                'total_modules_ever' => $activations->count(),
                'monthly_recurring' => $monthlySpend,
                'one_time_this_month' => $oneTimeSpend,
                'total_spent_all_time' => $activations->sum('paid_amount'),
                'currency' => 'RUB'
            ],
            'breakdown_by_type' => [
                'subscription' => $standaloneSubscriptionModules->count() + ($activeSubscription !== null ? 1 : 0),
                'one_time' => $activations
                    ->where('is_bundled_with_plan', false)
                    ->where('module.billing_model', 'one_time')
                    ->count(),
                'free' => $activeModules->where('module.billing_model', 'free')->count()
            ]
        ];
    }

    private function getActiveSubscription(int $organizationId): ?OrganizationSubscription
    {
        return OrganizationSubscription::with('plan')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('canceled_at')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();
    }
}
