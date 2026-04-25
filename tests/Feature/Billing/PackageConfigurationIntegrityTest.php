<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\TestCase;

class PackageConfigurationIntegrityTest extends TestCase
{
    private const SYSTEM_MODULES = [
        'organizations',
        'users',
    ];

    public function refreshDatabase(): void {}

    public function test_package_tiers_reference_existing_modules_and_close_dependencies(): void
    {
        $modules = $this->loadModules();

        foreach ($this->loadPackages() as $package) {
            foreach ($package['tiers'] ?? [] as $tier => $tierConfig) {
                $moduleSlugs = $tierConfig['modules'] ?? [];

                $this->assertModulesExist($modules, $moduleSlugs, "{$package['slug']}/{$tier}");
                $this->assertDependenciesClosed($modules, $moduleSlugs, "{$package['slug']}/{$tier}");
            }
        }
    }

    public function test_subscription_package_matrix_closes_dependencies(): void
    {
        $modules = $this->loadModules();
        $packages = collect($this->loadPackages())->keyBy('slug')->all();

        $includedPackagesByPlan = [
            'start' => [
                ['package_slug' => 'objects-execution', 'tier' => 'base'],
            ],
            'business' => [
                ['package_slug' => 'objects-execution', 'tier' => 'base'],
                ['package_slug' => 'supply-warehouse', 'tier' => 'base'],
                ['package_slug' => 'finance-acts', 'tier' => 'base'],
            ],
            'profi' => [
                ['package_slug' => 'objects-execution', 'tier' => 'pro'],
                ['package_slug' => 'supply-warehouse', 'tier' => 'pro'],
                ['package_slug' => 'finance-acts', 'tier' => 'pro'],
                ['package_slug' => 'estimates-pto', 'tier' => 'pro'],
                ['package_slug' => 'holding-analytics', 'tier' => 'pro'],
                ['package_slug' => 'ai-contour', 'tier' => 'pro'],
            ],
            'enterprise' => [
                ['package_slug' => 'objects-execution', 'tier' => 'enterprise'],
                ['package_slug' => 'finance-acts', 'tier' => 'enterprise'],
                ['package_slug' => 'supply-warehouse', 'tier' => 'enterprise'],
                ['package_slug' => 'holding-analytics', 'tier' => 'enterprise'],
                ['package_slug' => 'estimates-pto', 'tier' => 'enterprise'],
                ['package_slug' => 'ai-contour', 'tier' => 'enterprise'],
            ],
        ];

        foreach ($includedPackagesByPlan as $planSlug => $includedPackages) {
            $moduleSlugs = [];

            foreach ($includedPackages as $includedPackage) {
                $package = $packages[$includedPackage['package_slug']] ?? null;
                $this->assertNotNull($package, "Plan {$planSlug} references missing package {$includedPackage['package_slug']}");

                $tierConfig = $package['tiers'][$includedPackage['tier']] ?? null;
                $this->assertNotNull(
                    $tierConfig,
                    "Plan {$planSlug} references missing tier {$includedPackage['package_slug']}/{$includedPackage['tier']}"
                );

                $moduleSlugs = array_merge($moduleSlugs, $tierConfig['modules'] ?? []);
            }

            $moduleSlugs = array_values(array_unique($moduleSlugs));

            $this->assertModulesExist($modules, $moduleSlugs, $planSlug);
            $this->assertDependenciesClosed($modules, $moduleSlugs, $planSlug);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadModules(): array
    {
        $modules = [];

        foreach (glob(config_path('ModuleList/*/*.json')) ?: [] as $filePath) {
            $module = json_decode((string) file_get_contents($filePath), true);

            if (is_array($module) && isset($module['slug'])) {
                $modules[$module['slug']] = $module;
            }
        }

        return $modules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPackages(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $filePath): ?array => json_decode((string) file_get_contents($filePath), true),
            glob(config_path('Packages/*.json')) ?: []
        )));
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     * @param array<int, string> $moduleSlugs
     */
    private function assertModulesExist(array $modules, array $moduleSlugs, string $context): void
    {
        foreach ($moduleSlugs as $moduleSlug) {
            $this->assertArrayHasKey($moduleSlug, $modules, "{$context} references missing module {$moduleSlug}");
        }
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     * @param array<int, string> $moduleSlugs
     */
    private function assertDependenciesClosed(array $modules, array $moduleSlugs, string $context): void
    {
        $moduleSet = array_fill_keys($moduleSlugs, true);

        foreach ($moduleSlugs as $moduleSlug) {
            foreach ($modules[$moduleSlug]['dependencies'] ?? [] as $dependencySlug) {
                if (in_array($dependencySlug, self::SYSTEM_MODULES, true)) {
                    continue;
                }

                $this->assertArrayHasKey(
                    $dependencySlug,
                    $moduleSet,
                    "{$context} includes {$moduleSlug}, but misses dependency {$dependencySlug}"
                );
            }
        }
    }
}
