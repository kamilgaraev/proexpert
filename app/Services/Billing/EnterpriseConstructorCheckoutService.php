<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\EnterpriseConstructorSelection;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Repositories\Landing\OrganizationSubscriptionRepository;
use App\Services\SubscriptionModuleSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EnterpriseConstructorCheckoutService
{
    public function __construct(
        private readonly EnterpriseConstructorPricingService $pricingService,
        private readonly BalanceServiceInterface $balanceService,
        private readonly SubscriptionModuleSyncService $moduleSyncService,
        private readonly SubscriptionLimitsService $limitsService,
        private readonly OrganizationSubscriptionRepository $subscriptionRepository,
    ) {
    }

    public function checkout(int $organizationId, EnterpriseConstructorSelection $selection): array
    {
        $preview = $this->pricingService->preview($selection);

        if ($preview['requires_implementation_project']) {
            return [
                'success' => false,
                'status_code' => 422,
                'message' => trans_message('billing.enterprise_constructor.checkout_requires_project'),
                'preview' => $preview,
            ];
        }

        $organization = Organization::query()->findOrFail($organizationId);
        $plan = SubscriptionPlan::query()
            ->where('slug', 'enterprise')
            ->where('is_active', true)
            ->firstOrFail();

        return DB::transaction(function () use ($organization, $plan, $preview, $selection): array {
            $now = Carbon::now();
            $endsAt = $now->copy()->addDays((int) ($plan->duration_in_days ?: 30));
            $currentSubscription = $this->subscriptionRepository->getByOrganizationId($organization->id);
            $oldPlan = $currentSubscription?->plan;

            $subscription = $this->subscriptionRepository->createOrUpdate($organization->id, $plan->id, [
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'next_billing_at' => $endsAt,
                'canceled_at' => null,
                'is_auto_payment_enabled' => true,
                'enterprise_constructor_config' => [
                    'selection' => $selection->toArray(),
                    'price' => $preview['price'],
                    'limits' => $preview['limits'],
                    'selected_extensions' => $preview['selected_extensions'],
                    'checked_out_at' => $now->toISOString(),
                ],
            ])->fresh('plan');

            $amount = (int) $preview['price']['total'] * 100;

            $balance = $this->balanceService->debitBalance(
                $organization,
                $amount,
                trans_message('billing.enterprise_constructor.checkout_success'),
                $subscription,
                [
                    'type' => 'enterprise_constructor_checkout',
                    'price' => $preview['price'],
                    'selection' => $selection->toArray(),
                ]
            );

            $moduleSync = $this->syncModules($subscription, $oldPlan, $plan);
            $this->limitsService->clearOrganizationSubscriptionCache($organization->id);

            return [
                'success' => true,
                'message' => trans_message('billing.enterprise_constructor.checkout_success'),
                'subscription' => $subscription,
                'preview' => $preview,
                'balance' => [
                    'amount' => $balance->balance,
                    'currency' => $balance->currency,
                ],
                'module_sync' => $moduleSync,
            ];
        });
    }

    private function syncModules(
        OrganizationSubscription $subscription,
        ?SubscriptionPlan $oldPlan,
        SubscriptionPlan $newPlan
    ): array {
        if ($oldPlan instanceof SubscriptionPlan) {
            return $this->moduleSyncService->syncModulesOnPlanChange($subscription, $oldPlan, $newPlan);
        }

        return $this->moduleSyncService->syncModulesOnSubscribe($subscription);
    }
}
