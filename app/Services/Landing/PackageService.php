<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PackageService
{
    private const PACKAGES_PATH = 'Packages';

    public function getAllPackages(int $organizationId): array
    {
        $packageFiles = glob(config_path(self::PACKAGES_PATH.'/*.json'));
        $activeSubscriptions = $this->getOrganizationSubscriptions($organizationId);
        $activeModuleSlugs = $this->getActiveModuleSlugs($organizationId);

        $packages = [];

        foreach ($packageFiles as $filePath) {
            $config = json_decode(file_get_contents($filePath), true);

            if (! $config) {
                continue;
            }

            $packageSlug = $config['slug'];
            $subscription = $activeSubscriptions[$packageSlug] ?? null;

            $packages[] = $this->buildPackageData($config, $subscription, $activeModuleSlugs);
        }

        usort($packages, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $packages;
    }

    public function subscribeToPackage(
        int $organizationId,
        string $packageSlug,
        string $tier,
        int $durationDays = 30
    ): array {
        $config = $this->getPackageConfig($packageSlug);

        if (! isset($config['tiers'][$tier])) {
            throw new \InvalidArgumentException("Тир '{$tier}' не существует в пакете '{$packageSlug}'");
        }

        $tierConfig = $config['tiers'][$tier];
        $moduleSlugsList = $tierConfig['modules'];
        $price = (float) ($tierConfig['price'] ?? 0);

        return DB::transaction(function () use (
            $organizationId, $packageSlug, $tier, $durationDays, $price, $moduleSlugsList
        ) {
            $expiresAt = $price > 0 ? now()->addDays($durationDays) : null;

            OrganizationPackageSubscription::updateOrCreate(
                ['organization_id' => $organizationId, 'package_slug' => $packageSlug],
                ['tier' => $tier, 'price_paid' => $price, 'activated_at' => now(), 'expires_at' => $expiresAt]
            );

            $this->activateModules($organizationId, $moduleSlugsList, $expiresAt);

            return [
                'package_slug' => $packageSlug,
                'tier' => $tier,
                'modules' => $moduleSlugsList,
                'expires_at' => $expiresAt,
            ];
        });
    }

    public function unsubscribeFromPackage(int $organizationId, string $packageSlug): void
    {
        $config = $this->getPackageConfig($packageSlug);

        $subscription = OrganizationPackageSubscription::where('organization_id', $organizationId)
            ->where('package_slug', $packageSlug)
            ->first();

        if (! $subscription) {
            return;
        }

        $currentTier = $subscription->tier;
        $modulesToDeactivate = $config['tiers'][$currentTier]['modules'] ?? [];

        DB::transaction(function () use ($organizationId, $packageSlug, $modulesToDeactivate) {
            $modulesProtected = $this->getModulesProtectedByOtherPackages($organizationId, $packageSlug);
            $toDeactivate = array_diff($modulesToDeactivate, $modulesProtected);

            $this->deactivateModules($organizationId, $toDeactivate);

            OrganizationPackageSubscription::where('organization_id', $organizationId)
                ->where('package_slug', $packageSlug)
                ->delete();
        });
    }

    private function buildPackageData(array $config, ?OrganizationPackageSubscription $subscription, array $activeModuleSlugs): array
    {
        $activeTier = null;

        if ($subscription && $subscription->isActive()) {
            $activeTier = $subscription->tier;
        } else {
            $activeTier = $this->inferActiveTierFromModules($config['tiers'], $activeModuleSlugs);
        }

        $tiers = [];
        foreach ($config['tiers'] as $tierKey => $tierData) {
            $tiers[$tierKey] = [
                'label' => $tierData['label'],
                'description' => $tierData['description'],
                'price' => $tierData['price'],
                'modules' => $tierData['modules'],
                'highlights' => $tierData['highlights'] ?? [],
            ];
        }

        return [
            'slug' => $config['slug'],
            'name' => $config['name'],
            'description' => $config['description'],
            'icon' => $config['icon'],
            'color' => $config['color'],
            'sort_order' => $config['sort_order'] ?? 99,
            'tiers' => $tiers,
            'active_tier' => $activeTier,
            'expires_at' => $subscription?->expires_at?->toISOString(),
        ];
    }

    private function inferActiveTierFromModules(array $tiersConfig, array $activeModuleSlugs): ?string
    {
        $tierOrder = ['enterprise', 'pro', 'base'];
        $activeSet = array_flip($activeModuleSlugs);

        foreach ($tierOrder as $tierKey) {
            if (! isset($tiersConfig[$tierKey])) {
                continue;
            }

            $tierModules = $tiersConfig[$tierKey]['modules'] ?? [];

            if (empty($tierModules)) {
                continue;
            }

            $allActive = true;
            foreach ($tierModules as $slug) {
                if (! isset($activeSet[$slug])) {
                    $allActive = false;
                    break;
                }
            }

            if ($allActive) {
                return $tierKey;
            }
        }

        return null;
    }

    private function getActiveModuleSlugs(int $organizationId): array
    {
        return OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->join('modules', 'modules.id', '=', 'organization_module_activations.module_id')
            ->pluck('modules.slug')
            ->all();
    }

    private function activateModules(int $organizationId, array $moduleSlugs, ?\Carbon\Carbon $expiresAt): void
    {
        $modules = Module::whereIn('slug', $moduleSlugs)->get()->keyBy('slug');

        foreach ($moduleSlugs as $slug) {
            $module = $modules->get($slug);

            if (! $module) {
                Log::warning('PackageService: модуль не найден в БД', ['slug' => $slug]);

                continue;
            }

            OrganizationModuleActivation::updateOrCreate(
                ['organization_id' => $organizationId, 'module_id' => $module->id],
                [
                    'status' => 'active',
                    'activated_at' => now(),
                    'expires_at' => $expiresAt,
                    'is_bundled_with_plan' => true,
                ]
            );
        }
    }

    private function deactivateModules(int $organizationId, array $moduleSlugs): void
    {
        if (empty($moduleSlugs)) {
            return;
        }

        $modules = Module::whereIn('slug', $moduleSlugs)->get();

        foreach ($modules as $module) {
            OrganizationModuleActivation::where('organization_id', $organizationId)
                ->where('module_id', $module->id)
                ->where('is_bundled_with_plan', true)
                ->update(['status' => 'inactive', 'expires_at' => now()]);
        }
    }

    private function getModulesProtectedByOtherPackages(int $organizationId, string $excludePackageSlug): array
    {
        $otherSubscriptions = OrganizationPackageSubscription::where('organization_id', $organizationId)
            ->where('package_slug', '!=', $excludePackageSlug)
            ->active()
            ->get();

        $protected = [];

        foreach ($otherSubscriptions as $sub) {
            $config = $this->getPackageConfig($sub->package_slug);
            $tierModules = $config['tiers'][$sub->tier]['modules'] ?? [];
            $protected = array_merge($protected, $tierModules);
        }

        return array_unique($protected);
    }

    private function getOrganizationSubscriptions(int $organizationId): array
    {
        return OrganizationPackageSubscription::where('organization_id', $organizationId)
            ->active()
            ->get()
            ->keyBy('package_slug')
            ->all();
    }

    private function getPackageConfig(string $packageSlug): array
    {
        $path = config_path(self::PACKAGES_PATH.'/'.$packageSlug.'.json');

        if (! file_exists($path)) {
            throw new \RuntimeException("Конфигурация пакета '{$packageSlug}' не найдена");
        }

        return json_decode(file_get_contents($path), true);
    }
}
