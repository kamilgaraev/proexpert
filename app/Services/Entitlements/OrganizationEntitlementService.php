<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OrganizationEntitlementService
{
    private const PACKAGES_PATH = 'Packages';

    public function getEffectiveModuleSlugs(int $organizationId): array
    {
        $systemSlugs = Module::query()
            ->where('is_active', true)
            ->where('can_deactivate', false)
            ->pluck('slug')
            ->all();

        $directSlugs = OrganizationModuleActivation::query()
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

        $slugs = array_values(array_unique(array_merge(
            $systemSlugs,
            $directSlugs,
            array_keys($this->getPackageModuleSources($organizationId))
        )));

        if ($slugs === []) {
            return [];
        }

        return Module::query()
            ->where('is_active', true)
            ->whereIn('slug', $slugs)
            ->pluck('slug')
            ->all();
    }

    public function getEffectiveModules(int $organizationId): Collection
    {
        $slugs = $this->getEffectiveModuleSlugs($organizationId);

        if ($slugs === []) {
            return collect();
        }

        return Module::query()
            ->where('is_active', true)
            ->whereIn('slug', $slugs)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    public function hasModuleAccess(int $organizationId, string $moduleSlug): bool
    {
        return in_array($moduleSlug, $this->getEffectiveModuleSlugs($organizationId), true);
    }

    public function hasModulePermission(int $organizationId, string $permission): bool
    {
        foreach ($this->getEffectiveModules($organizationId) as $module) {
            foreach ((array) $module->permissions as $modulePermission) {
                if ($modulePermission === $permission) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getPackageModuleSources(int $organizationId): array
    {
        $sources = [];

        foreach ($this->getActivePlanPackageDefinitions($organizationId) as $package) {
            $this->appendPackageModuleSources($sources, $package, true);
        }

        $packageSubscriptions = OrganizationPackageSubscription::query()
            ->with('subscription')
            ->where('organization_id', $organizationId)
            ->active()
            ->get()
            ->filter(fn (OrganizationPackageSubscription $packageSubscription): bool => $this->isPackageSubscriptionUsable($packageSubscription));

        foreach ($packageSubscriptions as $packageSubscription) {
            $this->appendPackageModuleSources($sources, [
                'package_slug' => $packageSubscription->package_slug,
                'tier' => $packageSubscription->tier,
                'subscription_id' => $packageSubscription->subscription_id,
                'expires_at' => $packageSubscription->expires_at,
            ], (bool) $packageSubscription->is_bundled_with_plan);
        }

        return $sources;
    }

    private function getActivePlanPackageDefinitions(int $organizationId): array
    {
        $subscriptions = OrganizationSubscription::query()
            ->with('plan')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('canceled_at')
            ->where(function ($query): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->get();

        $packages = [];

        foreach ($subscriptions as $subscription) {
            if (! $subscription->plan instanceof SubscriptionPlan) {
                continue;
            }

            foreach ($this->normalizeIncludedPackages($subscription->plan) as $package) {
                $packages[] = [
                    'package_slug' => $package['package_slug'],
                    'tier' => $package['tier'],
                    'subscription_id' => $subscription->id,
                    'expires_at' => $subscription->ends_at,
                ];
            }
        }

        return $packages;
    }

    private function appendPackageModuleSources(array &$sources, array $package, bool $isBundledWithPlan): void
    {
        $packageSlug = $package['package_slug'] ?? null;
        $tier = $package['tier'] ?? null;

        if (! is_string($packageSlug) || ! is_string($tier)) {
            return;
        }

        foreach ($this->getPackageTierModules($packageSlug, $tier) as $moduleSlug) {
            $existingSource = $sources[$moduleSlug] ?? null;

            if ($existingSource !== null && ($existingSource['is_bundled_with_plan'] ?? false) && ! $isBundledWithPlan) {
                continue;
            }

            $sources[$moduleSlug] = [
                'module_slug' => $moduleSlug,
                'package_slug' => $packageSlug,
                'tier' => $tier,
                'subscription_id' => $package['subscription_id'] ?? null,
                'expires_at' => $package['expires_at'] ?? null,
                'is_bundled_with_plan' => $isBundledWithPlan,
            ];
        }
    }

    private function isPackageSubscriptionUsable(OrganizationPackageSubscription $packageSubscription): bool
    {
        if (! $packageSubscription->is_bundled_with_plan) {
            return true;
        }

        $subscription = $packageSubscription->subscription;

        return $subscription instanceof OrganizationSubscription
            && $subscription->status === 'active'
            && $subscription->canceled_at === null
            && ($subscription->ends_at === null || $subscription->ends_at->isFuture());
    }

    private function normalizeIncludedPackages(SubscriptionPlan $plan): array
    {
        return collect($plan->included_packages ?? [])
            ->filter(fn ($package): bool => is_array($package))
            ->map(function (array $package): ?array {
                $packageSlug = $package['package_slug'] ?? $package['slug'] ?? null;
                $tier = $package['tier'] ?? null;

                if (! is_string($packageSlug) || ! is_string($tier)) {
                    return null;
                }

                if ($this->getPackageTierModules($packageSlug, $tier) === []) {
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

    private function getPackageTierModules(string $packageSlug, string $tier): array
    {
        $config = $this->getPackageConfig($packageSlug);
        $modules = $config['tiers'][$tier]['modules'] ?? [];

        if (! is_array($modules)) {
            return [];
        }

        return array_values(array_filter($modules, fn ($moduleSlug): bool => is_string($moduleSlug)));
    }

    private function getPackageConfig(string $packageSlug): array
    {
        $path = config_path(self::PACKAGES_PATH.'/'.$packageSlug.'.json');

        if (! file_exists($path)) {
            Log::warning('Package config is missing for entitlement calculation', [
                'package_slug' => $packageSlug,
            ]);

            return ['tiers' => []];
        }

        $config = json_decode((string) file_get_contents($path), true);

        return is_array($config) ? $config : ['tiers' => []];
    }
}
