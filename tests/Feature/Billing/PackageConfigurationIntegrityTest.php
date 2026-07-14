<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Services\Modules\PackageCatalogService;
use Tests\TestCase;

class PackageConfigurationIntegrityTest extends TestCase
{
    private const EXPECTED_PACKAGES = [
        'projects-processes' => 9900,
        'planning-schedules' => 7900,
        'estimates-norms' => 12900,
        'quality-safety' => 9900,
        'pto-handover' => 11900,
        'supply-warehouse' => 11900,
        'finance-contracts' => 12900,
        'workforce-output' => 9900,
        'machinery' => 7900,
        'sales-contractors' => 7900,
    ];

    public function refreshDatabase(): void {}

    public function test_catalog_contains_exact_packages_in_stable_order_with_exact_prices(): void
    {
        $packages = $this->catalog()->allPackages();

        $this->assertSame(array_keys(self::EXPECTED_PACKAGES), array_column($packages, 'slug'));
        $this->assertSame(
            array_values(self::EXPECTED_PACKAGES),
            array_map(static fn (array $package): int => $package['tiers']['standard']['price'], $packages)
        );
        $this->assertSame(103000, array_sum(self::EXPECTED_PACKAGES));

        foreach ($packages as $index => $package) {
            $this->assertSame($index + 1, $package['sort_order']);
            $this->assertNotEmpty($package['description']);
            $this->assertSame(['standard'], array_keys($package['tiers']));
        }
    }

    public function test_package_directory_contains_only_expected_catalog_files(): void
    {
        $files = array_map('basename', glob(config_path('Packages/*.json')) ?: []);
        sort($files);
        $expectedFiles = array_map(static fn (string $slug): string => "{$slug}.json", array_keys(self::EXPECTED_PACKAGES));
        sort($expectedFiles);

        $this->assertSame($expectedFiles, $files);
    }

    public function test_standard_packages_reference_existing_modules_and_close_dependencies(): void
    {
        $modules = $this->loadModules();
        $foundationModules = $this->catalog()->foundationModules();

        foreach ($this->catalog()->allPackages() as $package) {
            $moduleSlugs = $package['tiers']['standard']['modules'];

            $this->assertNotEmpty($moduleSlugs, "{$package['slug']} must contain commercial modules");
            $this->assertSame([], array_values(array_intersect($foundationModules, $moduleSlugs)));
            $this->assertModulesExist($modules, $moduleSlugs, $package['slug']);
            $this->assertDependenciesClosed($modules, $moduleSlugs, $foundationModules, $package['slug']);
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
    private function catalog(): PackageCatalogService
    {
        return new PackageCatalogService();
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
    private function assertDependenciesClosed(
        array $modules,
        array $moduleSlugs,
        array $foundationModules,
        string $context
    ): void
    {
        $moduleSet = array_fill_keys(array_merge($foundationModules, $moduleSlugs), true);

        foreach ($moduleSlugs as $moduleSlug) {
            foreach ($modules[$moduleSlug]['dependencies'] ?? [] as $dependencySlug) {
                $this->assertArrayHasKey(
                    $dependencySlug,
                    $moduleSet,
                    "{$context} includes {$moduleSlug}, but misses dependency {$dependencySlug}"
                );
            }
        }
    }
}
