<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Modules\Core\AccessController;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use function trans_message;

class SubscriptionModuleSyncService
{
    private const PACKAGES_PATH = 'Packages';

    public function __construct(
        private readonly OrganizationEntitlementService $entitlementService
    ) {
    }

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
        $newModules = $this->getModulesForPlan($newPlan);
        $newModuleIds = $newModules->pluck('id');

        $modulesToRemove = $oldModuleIds->diff($newModuleIds);

        $deactivatedCount = 0;
        $activatedCount = 0;
        $convertedCount = 0;
        $packagesDeactivatedCount = 0;

        DB::transaction(function () use (
            $modulesToRemove,
            $newModules,
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

            foreach ($newModules as $module) {
                $result = $this->activateModuleForSubscription($organizationId, $module, $subscription);

                if ($result === 'converted') {
                    $convertedCount++;
                } elseif ($result === 'activated') {
                    $activatedCount++;
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

    public function ensureBundledModulesSyncedForOrganization(int $organizationId): array
    {
        $subscription = OrganizationSubscription::query()
            ->with('plan')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('canceled_at')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest('id')
            ->first();

        if (! $subscription || ! $subscription->plan) {
            return $this->emptySyncResult();
        }

        $includedPackages = $this->getIncludedPackages($subscription->plan);
        $bundledModuleSlugs = $this->getBundledModuleSlugsForPlan($subscription->plan);

        if ($includedPackages === [] && $bundledModuleSlugs === []) {
            return $this->emptySyncResult();
        }

        if (
            ! $this->hasMissingBundledPackage($organizationId, $subscription, $includedPackages)
            && ! $this->hasMissingBundledModule($organizationId, $bundledModuleSlugs)
        ) {
            return $this->emptySyncResult();
        }

        return $this->syncModulesOnSubscribe($subscription);
    }

    public function repairPackageModuleActivations(?int $organizationId = null): array
    {
        $organizationIds = $organizationId !== null
            ? Organization::query()->whereKey($organizationId)->pluck('id')
            : $this->getOrganizationsWithActivePackageEntitlements();

        $result = [
            'organizations_count' => 0,
            'created_count' => 0,
            'restored_count' => 0,
            'skipped_count' => 0,
            'missing_modules_count' => 0,
        ];

        foreach ($organizationIds as $id) {
            $organizationResult = $this->repairPackageModuleActivationsForOrganization((int) $id);
            $result['organizations_count']++;
            $result['created_count'] += $organizationResult['created_count'];
            $result['restored_count'] += $organizationResult['restored_count'];
            $result['skipped_count'] += $organizationResult['skipped_count'];
            $result['missing_modules_count'] += $organizationResult['missing_modules_count'];
        }

        return $result;
    }

    public function repairPackageModuleActivationsForOrganization(int $organizationId): array
    {
        $sources = $this->entitlementService->getPackageModuleSources($organizationId);

        if ($sources === []) {
            app(AccessController::class)->clearAccessCache($organizationId);

            return [
                'created_count' => 0,
                'restored_count' => 0,
                'skipped_count' => 0,
                'missing_modules_count' => 0,
            ];
        }

        $modules = Module::query()
            ->where('is_active', true)
            ->whereIn('slug', array_keys($sources))
            ->get()
            ->keyBy('slug');

        $result = [
            'created_count' => 0,
            'restored_count' => 0,
            'skipped_count' => 0,
            'missing_modules_count' => 0,
        ];

        DB::transaction(function () use ($organizationId, $sources, $modules, &$result): void {
            foreach ($sources as $moduleSlug => $source) {
                $module = $modules->get($moduleSlug);

                if (! $module instanceof Module) {
                    $result['missing_modules_count']++;
                    continue;
                }

                $existingActivation = OrganizationModuleActivation::query()
                    ->where('organization_id', $organizationId)
                    ->where('module_id', $module->id)
                    ->first();

                $attributes = [
                    'organization_id' => $organizationId,
                    'module_id' => $module->id,
                    'subscription_id' => $source['subscription_id'] ?? null,
                    'is_bundled_with_plan' => (bool) ($source['is_bundled_with_plan'] ?? false),
                    'status' => 'active',
                    'activated_at' => $existingActivation?->activated_at ?? now(),
                    'expires_at' => $source['expires_at'] ?? null,
                    'cancelled_at' => null,
                    'cancellation_reason' => null,
                    'paid_amount' => 0,
                    'module_settings' => $existingActivation?->module_settings ?? [],
                ];

                if (! $existingActivation) {
                    OrganizationModuleActivation::create($attributes);
                    $result['created_count']++;
                    continue;
                }

                if ($this->activationNeedsRepair($existingActivation, $attributes)) {
                    $existingActivation->update($attributes);
                    $result['restored_count']++;
                    continue;
                }

                $result['skipped_count']++;
            }
        });

        app(AccessController::class)->clearAccessCache($organizationId);

        return $result;
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

    private function getOrganizationsWithActivePackageEntitlements(): Collection
    {
        $packageOrganizationIds = OrganizationPackageSubscription::query()
            ->active()
            ->pluck('organization_id');

        $planOrganizationIds = OrganizationSubscription::query()
            ->with('plan')
            ->where('status', 'active')
            ->whereNull('canceled_at')
            ->where(function ($query): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->get()
            ->filter(fn (OrganizationSubscription $subscription): bool => $subscription->plan instanceof SubscriptionPlan
                && $this->getIncludedPackages($subscription->plan) !== [])
            ->pluck('organization_id');

        return $packageOrganizationIds
            ->merge($planOrganizationIds)
            ->unique()
            ->values();
    }

    private function activationNeedsRepair(OrganizationModuleActivation $activation, array $attributes): bool
    {
        if (! $activation->isActive()) {
            return true;
        }

        if ((int) $activation->subscription_id !== (int) ($attributes['subscription_id'] ?? 0)) {
            return true;
        }

        if ((bool) $activation->is_bundled_with_plan !== (bool) $attributes['is_bundled_with_plan']) {
            return true;
        }

        if ($activation->status !== 'active' || $activation->cancelled_at !== null || $activation->cancellation_reason !== null) {
            return true;
        }

        return $this->normalizeDateValue($activation->expires_at) !== $this->normalizeDateValue($attributes['expires_at'] ?? null);
    }

    private function normalizeDateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return is_string($value) ? $value : null;
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

    private function hasMissingBundledPackage(
        int $organizationId,
        OrganizationSubscription $subscription,
        array $includedPackages
    ): bool {
        foreach ($includedPackages as $package) {
            $existingPackage = OrganizationPackageSubscription::query()
                ->where('organization_id', $organizationId)
                ->where('package_slug', $package['package_slug'])
                ->first();

            if (
                ! $existingPackage
                || ! $existingPackage->isBundled()
                || ! $existingPackage->isActive()
                || $existingPackage->subscription_id !== $subscription->id
                || $existingPackage->tier !== $package['tier']
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasMissingBundledModule(int $organizationId, array $bundledModuleSlugs): bool
    {
        if ($bundledModuleSlugs === []) {
            return false;
        }

        $activeModuleSlugs = OrganizationModuleActivation::query()
            ->join('modules', 'modules.id', '=', 'organization_module_activations.module_id')
            ->where('organization_module_activations.organization_id', $organizationId)
            ->where('organization_module_activations.status', 'active')
            ->where('modules.is_active', true)
            ->where(function ($query) {
                $query->whereNull('organization_module_activations.expires_at')
                    ->orWhere('organization_module_activations.expires_at', '>', now());
            })
            ->pluck('modules.slug')
            ->all();

        return count(array_diff($bundledModuleSlugs, $activeModuleSlugs)) > 0;
    }

    private function emptySyncResult(): array
    {
        return [
            'success' => true,
            'activated_count' => 0,
            'converted_count' => 0,
            'packages_activated_count' => 0,
            'packages_converted_count' => 0,
            'modules' => [],
        ];
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
