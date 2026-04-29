<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\Organization;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Exceptions\Billing\InsufficientBalanceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PackageService
{
    private const PACKAGES_PATH = 'Packages';
    private const TIER_ORDER = ['base', 'pro', 'enterprise'];

    public function __construct(
        private readonly BalanceServiceInterface $balanceService
    ) {}

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

        $bundledSubscription = OrganizationPackageSubscription::where('organization_id', $organizationId)
            ->where('package_slug', $packageSlug)
            ->where('is_bundled_with_plan', true)
            ->active()
            ->first();
        $standaloneSubscription = OrganizationPackageSubscription::where('organization_id', $organizationId)
            ->where('package_slug', $packageSlug)
            ->where('is_bundled_with_plan', false)
            ->active()
            ->first();
        $coveredTier = $this->highestTier($bundledSubscription?->tier, $standaloneSubscription?->tier);

        if ($coveredTier !== null && $this->tierRank($coveredTier) >= $this->tierRank($tier)) {
            $coveredSubscription = $standaloneSubscription?->tier === $coveredTier
                ? $standaloneSubscription
                : $bundledSubscription;
            $coveredTierConfig = $config['tiers'][$coveredTier] ?? $tierConfig;

            return [
                'package_slug' => $packageSlug,
                'tier' => $coveredTier,
                'modules' => $coveredTierConfig['modules'] ?? $moduleSlugsList,
                'price_paid' => (float) ($coveredSubscription?->price_paid ?? 0),
                'expires_at' => $coveredSubscription?->expires_at,
                'access_source' => $coveredSubscription?->is_bundled_with_plan ? 'subscription' : 'standalone',
                'is_bundled_with_plan' => (bool) ($coveredSubscription?->is_bundled_with_plan ?? false),
            ];
        }

        $coveredPackagePrice = $coveredTier !== null ? (float) ($config['tiers'][$coveredTier]['price'] ?? 0) : 0;
        $standaloneModuleCredit = $this->calculateStandaloneModuleCredit($organizationId, $moduleSlugsList);
        $alreadyPaidSum = max($coveredPackagePrice, $standaloneModuleCredit);
        $upgradePrice = max(0, $price - $alreadyPaidSum);
        $amountCents = (int) round($upgradePrice * 100);

        // Проверяем баланс перед транзакцией, если нужно доплатить
        if ($upgradePrice > 0) {
            $organization = Organization::findOrFail($organizationId);
            $balance = $this->balanceService->getOrCreateOrganizationBalance($organization);
            if ($balance->balance < $amountCents) {
                throw new InsufficientBalanceException("Недостаточно средств. Необходимо пополнить баланс на " . (($amountCents - $balance->balance) / 100) . " руб.");
            }
        }

        return DB::transaction(function () use (
            $organizationId,
            $packageSlug,
            $tier,
            $durationDays,
            $price,
            $moduleSlugsList,
            $upgradePrice,
            $amountCents,
            $config,
            $tierConfig,
            $alreadyPaidSum,
            $bundledSubscription
        ) {
            $expiresAt = $price > 0 ? now()->addDays($durationDays) : null;
            $bundledModules = $bundledSubscription
                ? ($config['tiers'][$bundledSubscription->tier]['modules'] ?? [])
                : [];
            $modulesToActivate = array_values(array_diff($moduleSlugsList, $bundledModules));

            if ($upgradePrice > 0) {
                $organization = Organization::findOrFail($organizationId);
                $this->balanceService->debitBalance(
                    $organization,
                    $amountCents,
                    "Подключение пакета '{$config['name']}' (Тариф {$tierConfig['label']})".($alreadyPaidSum > 0 ? " со скидкой за активные модули" : "")
                );
            }

            OrganizationPackageSubscription::updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'package_slug' => $packageSlug,
                    'is_bundled_with_plan' => false,
                ],
                [
                    'subscription_id' => null,
                    'tier' => $tier,
                    'price_paid' => $upgradePrice,
                    'activated_at' => now(),
                    'expires_at' => $expiresAt,
                ]
            );

            $this->activateModules($organizationId, $modulesToActivate, $expiresAt, false);

            return [
                'package_slug' => $packageSlug,
                'tier' => $tier,
                'modules' => $moduleSlugsList,
                'price_paid' => $upgradePrice,
                'expires_at' => $expiresAt,
                'access_source' => 'standalone',
                'is_bundled_with_plan' => false,
            ];
        });
    }

    public function unsubscribeFromPackage(int $organizationId, string $packageSlug): void
    {
        $config = $this->getPackageConfig($packageSlug);

        $subscription = OrganizationPackageSubscription::where('organization_id', $organizationId)
            ->where('package_slug', $packageSlug)
            ->where('is_bundled_with_plan', false)
            ->first();

        if (! $subscription) {
            return;
        }

        $currentTier = $subscription->tier;
        $modulesToDeactivate = $config['tiers'][$currentTier]['modules'] ?? [];

        DB::transaction(function () use ($organizationId, $packageSlug, $modulesToDeactivate) {
            $modulesProtected = $this->getModulesProtectedByOtherPackages($organizationId, $packageSlug);
            $toDeactivate = array_diff($modulesToDeactivate, $modulesProtected);

            $this->deactivateModules($organizationId, $toDeactivate, false);

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
            'access_source' => $subscription?->is_bundled_with_plan ? 'subscription' : ($activeTier ? 'standalone' : null),
            'is_bundled_with_plan' => (bool) ($subscription?->is_bundled_with_plan ?? false),
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

    private function activateModules(
        int $organizationId,
        array $moduleSlugs,
        ?\Carbon\Carbon $expiresAt,
        bool $isBundledWithPlan
    ): void
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
                    'is_bundled_with_plan' => $isBundledWithPlan,
                ]
            );
        }
    }

    private function deactivateModules(int $organizationId, array $moduleSlugs, bool $isBundledWithPlan): void
    {
        if (empty($moduleSlugs)) {
            return;
        }

        $modules = Module::whereIn('slug', $moduleSlugs)->get();

        foreach ($modules as $module) {
            OrganizationModuleActivation::where('organization_id', $organizationId)
                ->where('module_id', $module->id)
                ->where('is_bundled_with_plan', $isBundledWithPlan)
                ->update(['status' => 'suspended', 'expires_at' => now()]);
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
            ->sortBy(fn (OrganizationPackageSubscription $subscription): int => $this->tierRank($subscription->tier))
            ->keyBy('package_slug')
            ->all();
    }

    private function calculateStandaloneModuleCredit(int $organizationId, array $moduleSlugsList): float
    {
        $activeModules = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('is_bundled_with_plan', false)
            ->whereHas('module', function ($q) use ($moduleSlugsList) {
                $q->whereIn('slug', $moduleSlugsList)->where('billing_model', '!=', 'free');
            })
            ->with('module')
            ->get();

        $alreadyPaidSum = 0;

        foreach ($activeModules as $activation) {
            $pricingConfig = $activation->module->pricing_config ?? [];
            $alreadyPaidSum += (float) ($pricingConfig['base_price'] ?? 0);
        }

        return $alreadyPaidSum;
    }

    private function highestTier(?string $firstTier, ?string $secondTier): ?string
    {
        if ($firstTier === null) {
            return $secondTier;
        }

        if ($secondTier === null) {
            return $firstTier;
        }

        return $this->tierRank($firstTier) >= $this->tierRank($secondTier) ? $firstTier : $secondTier;
    }

    private function tierRank(?string $tier): int
    {
        if ($tier === null) {
            return -1;
        }

        $rank = array_search($tier, self::TIER_ORDER, true);

        return $rank === false ? PHP_INT_MAX : (int) $rank;
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
