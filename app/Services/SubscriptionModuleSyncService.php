<?php

namespace App\Services;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use function trans_message;

class SubscriptionModuleSyncService
{
    private const PACKAGES_PATH = 'Packages';

    public function syncModulesOnSubscribe(OrganizationSubscription $subscription): array
    {
        $plan = $subscription->plan;
        $organizationId = $subscription->organization_id;
        $moduleSlugs = $this->getBundledModuleSlugsForPlan($plan);
        $modulesToActivate = Module::active()
            ->whereIn('slug', $moduleSlugs)
            ->get();

        $activatedCount = 0;
        $convertedCount = 0;
        $packagesActivatedCount = 0;
        $packagesConvertedCount = 0;
        $modules = [];

        DB::transaction(function () use (
            $plan,
            $modulesToActivate,
            $organizationId,
            $subscription,
            &$activatedCount,
            &$convertedCount,
            &$packagesActivatedCount,
            &$packagesConvertedCount,
            &$modules
        ) {
            foreach ($this->getIncludedPackages($plan) as $package) {
                $existingPackage = OrganizationPackageSubscription::query()
                    ->where('organization_id', $organizationId)
                    ->where('package_slug', $package['package_slug'])
                    ->first();

                if ($existingPackage?->isActive() && ! $existingPackage->isBundled()) {
                    $packagesConvertedCount++;
                } elseif (! $existingPackage) {
                    $packagesActivatedCount++;
                }

                OrganizationPackageSubscription::updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'package_slug' => $package['package_slug'],
                    ],
                    [
                        'subscription_id' => $subscription->id,
                        'is_bundled_with_plan' => true,
                        'tier' => $package['tier'],
                        'price_paid' => 0,
                        'activated_at' => now(),
                        'expires_at' => $subscription->ends_at,
                    ]
                );
            }

            foreach ($modulesToActivate as $module) {
                $result = $this->activateModuleForSubscription($organizationId, $module, $subscription);

                if ($result === 'converted') {
                    $convertedCount++;
                } elseif ($result === 'activated') {
                    $activatedCount++;
                }

                if ($result !== null) {
                    $modules[] = [
                        'slug' => $module->slug,
                        'name' => $module->name,
                        'action' => $result,
                    ];
                }
            }
        });

        return [
            'success' => true,
            'activated_count' => $activatedCount,
            'converted_count' => $convertedCount,
            'packages_activated_count' => $packagesActivatedCount,
            'packages_converted_count' => $packagesConvertedCount,
            'modules' => $modules,
        ];
    }

    public function syncModulesOnPlanChange(
        OrganizationSubscription $subscription,
        SubscriptionPlan $oldPlan,
        SubscriptionPlan $newPlan
    ): array {
        $organizationId = $subscription->organization_id;

        $oldModuleIds = $this->getModulesForPlan($oldPlan)->pluck('id');
        $newModuleIds = $this->getModulesForPlan($newPlan)->pluck('id');

        $modulesToRemove = $oldModuleIds->diff($newModuleIds);
        $modulesToAdd = $newModuleIds->diff($oldModuleIds);

        $deactivatedCount = 0;
        $activatedCount = 0;
        $convertedCount = 0;
        $packagesDeactivatedCount = 0;

        DB::transaction(function () use (
            $modulesToRemove,
            $modulesToAdd,
            $organizationId,
            $subscription,
            $newPlan,
            $oldPlan,
            &$deactivatedCount,
            &$activatedCount,
            &$convertedCount,
            &$packagesDeactivatedCount
        ) {
            if ($modulesToRemove->isNotEmpty()) {
                $deactivatedCount = OrganizationModuleActivation::where('organization_id', $organizationId)
                    ->whereIn('module_id', $modulesToRemove)
                    ->where('subscription_id', $subscription->id)
                    ->where('is_bundled_with_plan', true)
                    ->update([
                        'status' => 'suspended',
                        'cancelled_at' => now(),
                        'cancellation_reason' => trans_message(
                            'billing.modules.not_included_in_plan',
                            ['plan' => $newPlan->name]
                        ),
                    ]);
            }

            foreach ($this->getRemovedPackageKeys($oldPlan, $newPlan) as $package) {
                $packagesDeactivatedCount += OrganizationPackageSubscription::query()
                    ->where('organization_id', $organizationId)
                    ->where('subscription_id', $subscription->id)
                    ->where('is_bundled_with_plan', true)
                    ->where('package_slug', $package['package_slug'])
                    ->update(['expires_at' => now()]);
            }

            foreach ($this->getIncludedPackages($newPlan) as $package) {
                OrganizationPackageSubscription::updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'package_slug' => $package['package_slug'],
                    ],
                    [
                        'subscription_id' => $subscription->id,
                        'is_bundled_with_plan' => true,
                        'tier' => $package['tier'],
                        'price_paid' => 0,
                        'activated_at' => now(),
                        'expires_at' => $subscription->ends_at,
                    ]
                );
            }

            if ($modulesToAdd->isNotEmpty()) {
                foreach ($modulesToAdd as $moduleId) {
                    $module = Module::find($moduleId);

                    if (! $module) {
                        continue;
                    }

                    $result = $this->activateModuleForSubscription($organizationId, $module, $subscription);

                    if ($result === 'converted') {
                        $convertedCount++;
                    } elseif ($result === 'activated') {
                        $activatedCount++;
                    }
                }
            }
        });

        return [
            'success' => true,
            'deactivated_count' => $deactivatedCount,
            'activated_count' => $activatedCount,
            'converted_count' => $convertedCount,
            'packages_deactivated_count' => $packagesDeactivatedCount,
        ];
    }

    public function syncModulesOnRenew(OrganizationSubscription $subscription): int
    {
        $updatedCount = $subscription->syncModulesExpiration();
        $packagesUpdatedCount = $subscription->syncPackagesExpiration();

        Log::info('Bundled modules renewed with subscription', [
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'modules_count' => $updatedCount,
            'packages_count' => $packagesUpdatedCount,
            'new_expires_at' => $subscription->ends_at,
        ]);

        return $updatedCount;
    }

    public function handleSubscriptionCancellation(OrganizationSubscription $subscription): int
    {
        $deactivatedCount = $subscription->deactivateBundledModules(trans_message('billing.subscription.cancelled'));
        $expiredPackagesCount = $subscription->expireBundledPackages();

        Log::warning('Bundled modules deactivated due to subscription cancellation', [
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'modules_count' => $deactivatedCount,
            'packages_count' => $expiredPackagesCount,
        ]);

        return $deactivatedCount;
    }

    public function handleSubscriptionReactivation(OrganizationSubscription $subscription): int
    {
        $reactivatedCount = $subscription->reactivateBundledModules();
        $subscription->syncPackagesExpiration();

        Log::info('Bundled modules reactivated with subscription', [
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'modules_count' => $reactivatedCount,
        ]);

        return $reactivatedCount;
    }

    public function getBundledModulesForPlan(string $planSlug): array
    {
        $plan = SubscriptionPlan::where('slug', $planSlug)->first();

        if (! $plan) {
            return [];
        }

        return $this->getModulesForPlan($plan)
            ->map(function ($module) {
                return [
                    'id' => $module->id,
                    'name' => $module->name,
                    'slug' => $module->slug,
                    'description' => $module->description,
                    'category' => $module->category,
                    'features' => $module->features,
                    'icon' => $module->icon,
                ];
            })
            ->toArray();
    }

    private function activateModuleForSubscription(
        int $organizationId,
        Module $module,
        OrganizationSubscription $subscription
    ): ?string {
        $existingActivation = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('module_id', $module->id)
            ->first();

        if ($existingActivation) {
            if ($existingActivation->isStandalone() && $existingActivation->isActive()) {
                $existingActivation->convertToBundled($subscription);

                return 'converted';
            }

            if ($existingActivation->isBundled()) {
                $existingActivation->update([
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
        $slugs = Module::active()
            ->includedInPlan($plan->slug)
            ->pluck('slug')
            ->all();

        foreach ($this->getIncludedPackages($plan) as $package) {
            $config = $this->getPackageConfig($package['package_slug']);
            $tierModules = $config['tiers'][$package['tier']]['modules'] ?? [];
            $slugs = array_merge($slugs, $tierModules);
        }

        return array_values(array_unique($slugs));
    }

    private function getRemovedPackageKeys(SubscriptionPlan $oldPlan, SubscriptionPlan $newPlan): array
    {
        $newKeys = collect($this->getIncludedPackages($newPlan))
            ->mapWithKeys(fn (array $package): array => [
                $this->packageKey($package) => true,
            ]);

        return collect($this->getIncludedPackages($oldPlan))
            ->filter(fn (array $package): bool => ! $newKeys->has($this->packageKey($package)))
            ->values()
            ->all();
    }

    private function getIncludedPackages(SubscriptionPlan $plan): array
    {
        return collect($plan->included_packages ?? [])
            ->filter(fn ($package): bool => is_array($package))
            ->map(function (array $package): ?array {
                $packageSlug = $package['package_slug'] ?? $package['slug'] ?? null;
                $tier = $package['tier'] ?? null;

                if (! is_string($packageSlug) || ! is_string($tier)) {
                    return null;
                }

                $config = $this->getPackageConfig($packageSlug);

                if (! isset($config['tiers'][$tier])) {
                    return null;
                }

                return [
                    'package_slug' => $packageSlug,
                    'tier' => $tier,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function packageKey(array $package): string
    {
        return $package['package_slug'].':'.$package['tier'];
    }

    private function getPackageConfig(string $packageSlug): array
    {
        $path = config_path(self::PACKAGES_PATH.'/'.$packageSlug.'.json');

        if (! file_exists($path)) {
            return ['tiers' => []];
        }

        return json_decode((string) file_get_contents($path), true) ?: ['tiers' => []];
    }
}
