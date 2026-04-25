<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ModulesOverviewService
{
    private const PACKAGES_PATH = 'Packages';
    private const TIER_ORDER = ['base', 'pro', 'enterprise'];

    public function build(int $organizationId): array
    {
        $packages = $this->loadPackages();
        $membership = $this->buildPackageMembership($packages);
        $activations = $this->getActivations($organizationId);
        $activeSlugs = $activations->keys()->all();
        $modules = Module::query()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $solutions = $this->buildSolutions($organizationId, $packages, $activations, $activeSlugs);
        $advancedModules = $modules
            ->map(fn (Module $module): array => $this->buildAdvancedModule($module, $membership, $activations))
            ->values()
            ->all();

        $standaloneModules = collect($advancedModules)
            ->filter(fn (array $module): bool => $module['classification'] === 'standalone')
            ->values()
            ->all();

        $expiringCount = collect($advancedModules)
            ->filter(fn (array $module): bool => $this->isExpiringSoon($module['activation']['expires_at'] ?? null))
            ->count();

        return [
            'summary' => [
                'active_solutions_count' => collect($solutions)->whereNotNull('current_tier')->count(),
                'total_solutions_count' => count($solutions),
                'active_standalone_count' => collect($standaloneModules)->where('status', 'active')->count(),
                'monthly_total' => $this->calculateMonthlyTotal($organizationId, $solutions, $standaloneModules),
                'expiring_count' => $expiringCount,
            ],
            'solutions' => $solutions,
            'standalone_modules' => $standaloneModules,
            'advanced_modules' => $advancedModules,
            'warnings' => $this->buildWarnings($expiringCount),
        ];
    }

    private function loadPackages(): array
    {
        $packageFiles = glob(config_path(self::PACKAGES_PATH . '/*.json'));
        $packages = [];

        foreach ($packageFiles as $filePath) {
            $config = json_decode((string) file_get_contents($filePath), true);

            if (! is_array($config) || ! isset($config['slug'], $config['tiers'])) {
                continue;
            }

            $packages[] = $config;
        }

        usort($packages, fn (array $a, array $b): int => ($a['sort_order'] ?? 99) <=> ($b['sort_order'] ?? 99));

        return $packages;
    }

    private function buildPackageMembership(array $packages): array
    {
        $membership = [];

        foreach ($packages as $package) {
            foreach ($package['tiers'] as $tier) {
                foreach (($tier['modules'] ?? []) as $moduleSlug) {
                    $membership[$moduleSlug] ??= [];
                    $membership[$moduleSlug][] = $package['slug'];
                }
            }
        }

        foreach ($membership as $moduleSlug => $packageSlugs) {
            $membership[$moduleSlug] = array_values(array_unique($packageSlugs));
        }

        return $membership;
    }

    private function getActivations(int $organizationId): Collection
    {
        return OrganizationModuleActivation::query()
            ->where('organization_id', $organizationId)
            ->with('module')
            ->get()
            ->filter(fn (OrganizationModuleActivation $activation): bool => $activation->module !== null)
            ->keyBy(fn (OrganizationModuleActivation $activation): string => $activation->module->slug);
    }

    private function buildSolutions(int $organizationId, array $packages, Collection $activations, array $activeSlugs): array
    {
        return collect($packages)
            ->map(function (array $package) use ($organizationId, $activations, $activeSlugs): array {
                $subscription = OrganizationPackageSubscription::query()
                    ->where('organization_id', $organizationId)
                    ->where('package_slug', $package['slug'])
                    ->active()
                    ->first();

                $currentTier = $subscription?->tier ?? $this->inferActiveTier($package['tiers'], $activeSlugs);
                $tiers = $this->buildTiers($package['tiers'], $currentTier, $activations);
                $currentTierData = $currentTier !== null ? ($package['tiers'][$currentTier] ?? null) : null;

                return [
                    'slug' => $package['slug'],
                    'name' => $package['name'],
                    'description' => $package['description'],
                    'icon' => $package['icon'],
                    'color' => $package['color'],
                    'current_tier' => $currentTier,
                    'active_tier' => $currentTier,
                    'effective_monthly_price' => (float) ($currentTierData['price'] ?? 0),
                    'included_modules_count' => $currentTierData ? count($currentTierData['modules'] ?? []) : count($this->uniquePackageModules($package)),
                    'active_included_modules_count' => $currentTierData ? $this->countActiveModules($currentTierData['modules'] ?? [], $activeSlugs) : 0,
                    'can_upgrade' => $this->hasHigherTier($package['tiers'], $currentTier),
                    'can_downgrade' => $this->hasLowerTier($package['tiers'], $currentTier),
                    'expires_at' => $subscription?->expires_at?->toISOString(),
                    'access_source' => $subscription?->is_bundled_with_plan ? 'subscription' : ($currentTier ? 'standalone' : null),
                    'is_bundled_with_plan' => (bool) ($subscription?->is_bundled_with_plan ?? false),
                    'tiers' => $tiers,
                ];
            })
            ->values()
            ->all();
    }

    private function buildTiers(array $tiersConfig, ?string $currentTier, Collection $activations): array
    {
        $activeSlugs = $activations->keys()->all();

        return collect(self::TIER_ORDER)
            ->filter(fn (string $tierKey): bool => isset($tiersConfig[$tierKey]))
            ->map(function (string $tierKey) use ($tiersConfig, $currentTier, $activeSlugs): array {
                $tier = $tiersConfig[$tierKey];
                $modules = $tier['modules'] ?? [];

                return [
                    'key' => $tierKey,
                    'label' => $tier['label'],
                    'description' => $tier['description'],
                    'price' => (float) ($tier['price'] ?? 0),
                    'modules' => $modules,
                    'highlights' => $tier['highlights'] ?? [],
                    'is_current' => $currentTier === $tierKey,
                    'included_modules_count' => count($modules),
                    'active_modules_count' => $this->countActiveModules($modules, $activeSlugs),
                ];
            })
            ->values()
            ->all();
    }

    private function buildAdvancedModule(Module $module, array $membership, Collection $activations): array
    {
        $activation = $activations->get($module->slug);
        $isSystem = $this->isSystemModule($module);
        $packageSlugs = $membership[$module->slug] ?? [];
        $classification = $isSystem ? 'system' : (empty($packageSlugs) ? 'standalone' : 'packaged');
        $status = $activation?->status ?? ($module->is_active ? 'available' : 'unavailable');
        $developmentStatus = $module->getDevelopmentStatusInfo();
        $price = (float) ($module->pricing_config['base_price'] ?? 0);

        return [
            'slug' => $module->slug,
            'name' => $module->name,
            'description' => $module->description,
            'classification' => $classification,
            'package_slugs' => $packageSlugs,
            'is_system' => $isSystem,
            'is_bundled_with_plan' => (bool) ($activation?->is_bundled_with_plan ?? false),
            'billing_model' => $module->billing_model,
            'price' => $price,
            'currency' => $module->pricing_config['currency'] ?? $module->currency ?? 'RUB',
            'status' => $status,
            'activation' => $activation ? [
                'status' => $activation->status,
                'activated_at' => $activation->activated_at?->toISOString(),
                'expires_at' => $activation->expires_at?->toISOString(),
                'days_until_expiration' => $activation->getDaysUntilExpiration(),
                'is_auto_renew_enabled' => (bool) ($activation->is_auto_renew_enabled ?? false),
                'is_bundled_with_plan' => (bool) ($activation->is_bundled_with_plan ?? false),
            ] : null,
            'development_status' => $developmentStatus,
            'can_activate' => $activation === null && $module->is_active && ($developmentStatus['can_be_activated'] ?? true),
            'can_deactivate' => (bool) ($module->can_deactivate ?? true),
            'icon' => $module->icon,
            'category' => $module->category,
            'features' => $module->features ?? [],
        ];
    }

    private function inferActiveTier(array $tiersConfig, array $activeSlugs): ?string
    {
        $activeSet = array_flip($activeSlugs);

        foreach (array_reverse(self::TIER_ORDER) as $tierKey) {
            if (! isset($tiersConfig[$tierKey])) {
                continue;
            }

            $modules = $tiersConfig[$tierKey]['modules'] ?? [];

            if ($modules !== [] && collect($modules)->every(fn (string $slug): bool => isset($activeSet[$slug]))) {
                return $tierKey;
            }
        }

        return null;
    }

    private function uniquePackageModules(array $package): array
    {
        $modules = [];

        foreach ($package['tiers'] as $tier) {
            $modules = array_merge($modules, $tier['modules'] ?? []);
        }

        return array_values(array_unique($modules));
    }

    private function countActiveModules(array $modules, array $activeSlugs): int
    {
        return count(array_intersect($modules, $activeSlugs));
    }

    private function hasHigherTier(array $tiersConfig, ?string $currentTier): bool
    {
        if ($currentTier === null) {
            return count($tiersConfig) > 0;
        }

        $currentIndex = array_search($currentTier, self::TIER_ORDER, true);

        return collect(self::TIER_ORDER)
            ->slice((int) $currentIndex + 1)
            ->contains(fn (string $tierKey): bool => isset($tiersConfig[$tierKey]));
    }

    private function hasLowerTier(array $tiersConfig, ?string $currentTier): bool
    {
        if ($currentTier === null) {
            return false;
        }

        $currentIndex = array_search($currentTier, self::TIER_ORDER, true);

        return collect(self::TIER_ORDER)
            ->take((int) $currentIndex)
            ->contains(fn (string $tierKey): bool => isset($tiersConfig[$tierKey]));
    }

    private function isSystemModule(Module $module): bool
    {
        return (bool) ($module->is_system_module ?? false)
            || (($module->can_deactivate ?? true) === false && $module->billing_model === 'free');
    }

    private function calculateMonthlyTotal(int $organizationId, array $solutions, array $standaloneModules): float
    {
        $subscriptionTotal = $this->getActiveSubscriptionMonthlyPrice($organizationId);
        $solutionsTotal = collect($solutions)
            ->reject(fn (array $solution): bool => (bool) ($solution['is_bundled_with_plan'] ?? false))
            ->sum('effective_monthly_price');
        $standaloneTotal = collect($standaloneModules)
            ->filter(fn (array $module): bool => $module['status'] === 'active'
                && $module['billing_model'] !== 'free'
                && ! (bool) ($module['is_bundled_with_plan'] ?? false))
            ->sum('price');

        return (float) ($subscriptionTotal + $solutionsTotal + $standaloneTotal);
    }

    private function getActiveSubscriptionMonthlyPrice(int $organizationId): float
    {
        $subscription = OrganizationSubscription::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->whereNull('canceled_at')
            ->with('plan')
            ->latest('id')
            ->first();

        return (float) ($subscription?->plan?->price ?? 0);
    }

    private function isExpiringSoon(?string $expiresAt): bool
    {
        if ($expiresAt === null) {
            return false;
        }

        $date = CarbonImmutable::parse($expiresAt);

        return $date->between(now(), now()->addDays(7));
    }

    private function buildWarnings(int $expiringCount): array
    {
        if ($expiringCount === 0) {
            return [];
        }

        return [[
            'type' => 'expiring',
            'message' => "{$expiringCount} возможностей скоро истекает",
            'count' => $expiringCount,
        ]];
    }
}
