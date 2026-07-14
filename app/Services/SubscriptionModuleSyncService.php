<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

class SubscriptionModuleSyncService
{
    public function syncModulesOnSubscribe(OrganizationSubscription $subscription): array
    {
        $modules = $this->getModulesForPlan($subscription->plan);
        $activated = 0;
        $converted = 0;
        $changes = [];

        DB::transaction(function () use ($subscription, $modules, &$activated, &$converted, &$changes): void {
            foreach ($modules as $module) {
                $result = $this->activateModuleForSubscription($subscription->organization_id, $module, $subscription);

                if ($result === 'activated') {
                    $activated++;
                } elseif ($result === 'converted') {
                    $converted++;
                }

                if ($result !== null) {
                    $changes[] = ['slug' => $module->slug, 'name' => $module->name, 'action' => $result];
                }
            }
        });

        return [
            'success' => true,
            'activated_count' => $activated,
            'converted_count' => $converted,
            'modules' => $changes,
        ];
    }

    public function syncModulesOnPlanChange(
        OrganizationSubscription $subscription,
        SubscriptionPlan $oldPlan,
        SubscriptionPlan $newPlan,
    ): array {
        $oldIds = $this->getModulesForPlan($oldPlan)->pluck('id');
        $newModules = $this->getModulesForPlan($newPlan);
        $removeIds = $oldIds->diff($newModules->pluck('id'));
        $deactivated = 0;
        $activated = 0;
        $converted = 0;

        DB::transaction(function () use (
            $subscription,
            $newPlan,
            $newModules,
            $removeIds,
            &$deactivated,
            &$activated,
            &$converted,
        ): void {
            if ($removeIds->isNotEmpty()) {
                $deactivated = OrganizationModuleActivation::query()
                    ->where('organization_id', $subscription->organization_id)
                    ->whereIn('module_id', $removeIds)
                    ->where('subscription_id', $subscription->id)
                    ->where('is_bundled_with_plan', true)
                    ->update([
                        'status' => 'suspended',
                        'cancelled_at' => now(),
                        'cancellation_reason' => trans_message(
                            'billing.modules.not_included_in_plan',
                            ['plan' => $newPlan->name],
                        ),
                    ]);
            }

            foreach ($newModules as $module) {
                $result = $this->activateModuleForSubscription(
                    $subscription->organization_id,
                    $module,
                    $subscription,
                );

                if ($result === 'activated') {
                    $activated++;
                } elseif ($result === 'converted') {
                    $converted++;
                }
            }
        });

        return [
            'success' => true,
            'deactivated_count' => $deactivated,
            'activated_count' => $activated,
            'converted_count' => $converted,
        ];
    }

    public function syncModulesOnRenew(OrganizationSubscription $subscription): int
    {
        $updated = $subscription->syncModulesExpiration();

        Log::info('Bundled modules renewed with subscription', [
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'modules_count' => $updated,
            'new_expires_at' => $subscription->ends_at,
        ]);

        return $updated;
    }

    public function handleSubscriptionCancellation(OrganizationSubscription $subscription): int
    {
        return $subscription->deactivateBundledModules(trans_message('billing.subscription.cancelled'));
    }

    public function handleSubscriptionReactivation(OrganizationSubscription $subscription): int
    {
        return $subscription->reactivateBundledModules();
    }

    public function ensureBundledModulesSyncedForOrganization(int $organizationId): array
    {
        $subscription = OrganizationSubscription::query()
            ->with('plan')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('canceled_at')
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->latest('id')
            ->first();

        if ($subscription?->plan === null) {
            return $this->emptyResult();
        }

        $slugs = $this->getBundledModuleSlugsForPlan($subscription->plan);

        if ($slugs === [] || ! $this->hasMissingBundledModule($organizationId, $slugs)) {
            return $this->emptyResult();
        }

        return $this->syncModulesOnSubscribe($subscription);
    }

    public function getBundledModulesForPlan(string $planSlug): array
    {
        $plan = SubscriptionPlan::query()->where('slug', $planSlug)->first();

        if ($plan === null) {
            return [];
        }

        return $this->getModulesForPlan($plan)
            ->map(static fn (Module $module): array => [
                'id' => $module->id,
                'name' => $module->name,
                'slug' => $module->slug,
                'description' => $module->description,
                'category' => $module->category,
                'features' => $module->features,
                'icon' => $module->icon,
            ])
            ->all();
    }

    private function activateModuleForSubscription(
        int $organizationId,
        Module $module,
        OrganizationSubscription $subscription,
    ): ?string {
        $activation = OrganizationModuleActivation::query()
            ->where('organization_id', $organizationId)
            ->where('module_id', $module->id)
            ->first();

        if ($activation !== null) {
            if ($activation->isStandalone() && $activation->isActive()) {
                $activation->convertToBundled($subscription);

                return 'converted';
            }

            if ($activation->isBundled()) {
                $activation->update([
                    'subscription_id' => $subscription->id,
                    'status' => 'active',
                    'expires_at' => $subscription->ends_at,
                    'next_billing_date' => $subscription->next_billing_at,
                    'cancelled_at' => null,
                    'cancellation_reason' => null,
                    'paid_amount' => 0,
                ]);
            }

            return null;
        }

        OrganizationModuleActivation::create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'subscription_id' => $subscription->id,
            'is_bundled_with_plan' => true,
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => $subscription->ends_at,
            'next_billing_date' => $subscription->next_billing_at,
            'paid_amount' => 0,
            'module_settings' => [],
        ]);

        return 'activated';
    }

    private function getModulesForPlan(SubscriptionPlan $plan): Collection
    {
        return Module::active()
            ->whereIn('slug', $this->getBundledModuleSlugsForPlan($plan))
            ->get();
    }

    private function getBundledModuleSlugsForPlan(SubscriptionPlan $plan): array
    {
        return Module::active()
            ->includedInPlan($plan->slug)
            ->pluck('slug')
            ->all();
    }

    private function hasMissingBundledModule(int $organizationId, array $slugs): bool
    {
        $active = OrganizationModuleActivation::query()
            ->join('modules', 'modules.id', '=', 'organization_module_activations.module_id')
            ->where('organization_module_activations.organization_id', $organizationId)
            ->where('organization_module_activations.status', 'active')
            ->where('modules.is_active', true)
            ->where(function ($query): void {
                $query->whereNull('organization_module_activations.expires_at')
                    ->orWhere('organization_module_activations.expires_at', '>', now());
            })
            ->pluck('modules.slug')
            ->all();

        return array_diff($slugs, $active) !== [];
    }

    private function emptyResult(): array
    {
        return [
            'success' => true,
            'activated_count' => 0,
            'converted_count' => 0,
            'modules' => [],
        ];
    }
}
