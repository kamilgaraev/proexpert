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

    private const EXPECTED_MODULES = [
        'projects-processes' => ['site-requests', 'file-management', 'ai-assistant', 'data-export'],
        'planning-schedules' => ['schedule-management'],
        'estimates-norms' => ['budget-estimates', 'rate-management', 'ai-estimates'],
        'quality-safety' => [
            'budget-estimates',
            'file-management',
            'quality-control',
            'safety-management',
            'video-monitoring',
            'access_recertification',
        ],
        'pto-handover' => [
            'budget-estimates',
            'file-management',
            'quality-control',
            'report-templates',
            'executive-documentation',
            'design-management',
            'handover-acceptance',
        ],
        'supply-warehouse' => ['site-requests', 'basic-warehouse', 'procurement', 'material-analytics'],
        'finance-contracts' => [
            'budget-estimates',
            'budgeting',
            'change-management',
            'advance-accounting',
            'one-c-basic-exchange',
        ],
        'workforce-output' => [
            'time-tracking',
            'budget-estimates',
            'workforce-management',
            'production-labor',
        ],
        'machinery' => ['budget-estimates', 'site-requests', 'machinery-operations'],
        'sales-contractors' => [
            'crm',
            'commercial-proposals',
            'contractor-portal',
            'file-management',
            'tenders',
        ],
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

            $this->assertSame(self::EXPECTED_MODULES[$package['slug']], $moduleSlugs);
            $this->assertNotEmpty($moduleSlugs, "{$package['slug']} must contain commercial modules");
            $this->assertSame([], array_values(array_intersect($foundationModules, $moduleSlugs)));
            $this->assertModulesExist($modules, $moduleSlugs, $package['slug']);
            $this->assertDependenciesClosed($modules, $moduleSlugs, $foundationModules, $package['slug']);
        }
    }

    public function test_every_module_has_an_explicit_catalog_role_and_every_bundled_module_is_assigned(): void
    {
        $modules = $this->loadModules();
        $classifications = $this->catalog()->moduleClassifications();
        $assignedModules = [];

        foreach ($this->catalog()->allPackages() as $package) {
            foreach ($package['tiers']['standard']['modules'] as $moduleSlug) {
                $assignedModules[$moduleSlug] = true;
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys($modules), array_keys($classifications))));
        $this->assertSame([], array_values(array_diff(array_keys($classifications), array_keys($modules))));

        foreach ($classifications as $moduleSlug => $classification) {
            $this->assertContains(
                $classification,
                ['foundation', 'package', 'addon', 'enterprise', 'planned', 'internal'],
                "{$moduleSlug} has unsupported catalog role {$classification}"
            );

            if (! in_array($classification, ['package', 'addon'], true)) {
                continue;
            }

            $this->assertArrayHasKey($moduleSlug, $assignedModules, "{$moduleSlug} is not assigned to a package");
        }
    }

    public function test_paid_package_modules_cannot_bypass_package_access(): void
    {
        $modules = $this->loadModules();

        foreach ($this->catalog()->allPackages() as $package) {
            foreach ($package['tiers']['standard']['modules'] as $moduleSlug) {
                $this->assertFalse(
                    (bool) ($modules[$moduleSlug]['auto_activate'] ?? false),
                    "{$moduleSlug} must not auto-activate outside package billing"
                );
                $this->assertFalse(
                    (bool) ($modules[$moduleSlug]['is_system_module'] ?? false),
                    "{$moduleSlug} must not bypass package billing as a system module"
                );
            }
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
        return new PackageCatalogService;
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     * @param  array<int, string>  $moduleSlugs
     */
    private function assertModulesExist(array $modules, array $moduleSlugs, string $context): void
    {
        foreach ($moduleSlugs as $moduleSlug) {
            $this->assertArrayHasKey($moduleSlug, $modules, "{$context} references missing module {$moduleSlug}");
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     * @param  array<int, string>  $moduleSlugs
     */
    private function assertDependenciesClosed(
        array $modules,
        array $moduleSlugs,
        array $foundationModules,
        string $context
    ): void {
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
