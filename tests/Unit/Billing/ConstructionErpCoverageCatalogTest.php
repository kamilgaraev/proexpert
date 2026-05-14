<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Modules\PackageCatalogService;
use PHPUnit\Framework\TestCase;

class ConstructionErpCoverageCatalogTest extends TestCase
{
    private const CONSTRUCTION_MODULES = [
        'quality-control',
        'executive-documentation',
        'safety-management',
        'machinery-operations',
        'production-labor',
        'change-management',
        'handover-acceptance',
    ];

    private const CONSTRUCTION_PACKAGES = [
        'site-quality-handover',
        'construction-safety',
        'machinery-and-labor',
        'change-control',
    ];

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = dirname(__DIR__, 3);
    }

    public function test_construction_coverage_modules_are_registered_and_classified(): void
    {
        $catalog = $this->catalog();
        $modules = $catalog->moduleDefinitions();
        $classifications = $catalog->moduleClassifications();

        foreach (self::CONSTRUCTION_MODULES as $moduleSlug) {
            $this->assertArrayHasKey($moduleSlug, $modules);
            $this->assertSame($moduleSlug, $modules[$moduleSlug]['slug']);
            $this->assertSame('package', $classifications[$moduleSlug] ?? null);
            $this->assertNotEmpty($modules[$moduleSlug]['permissions'] ?? []);
            $this->assertContains($moduleSlug . '.view', $modules[$moduleSlug]['permissions']);
        }
    }

    public function test_construction_coverage_packages_are_registered_with_expected_modules(): void
    {
        $catalog = $this->catalog();
        $packages = collect($catalog->allPackages())->keyBy('slug');

        foreach (self::CONSTRUCTION_PACKAGES as $packageSlug) {
            $this->assertTrue($packages->has($packageSlug), "Missing package {$packageSlug}");
            $this->assertSame(2, $packages[$packageSlug]['schema_version']);
        }

        $this->assertTierContains($catalog, 'site-quality-handover', 'base', [
            'quality-control',
            'handover-acceptance',
        ]);
        $this->assertTierContains($catalog, 'site-quality-handover', 'pro', [
            'quality-control',
            'executive-documentation',
            'handover-acceptance',
        ]);
        $this->assertTierContains($catalog, 'construction-safety', 'base', [
            'safety-management',
        ]);
        $this->assertTierContains($catalog, 'machinery-and-labor', 'base', [
            'machinery-operations',
            'production-labor',
        ]);
        $this->assertTierContains($catalog, 'change-control', 'base', [
            'change-management',
        ]);
    }

    public function test_core_roles_receive_construction_coverage_permissions(): void
    {
        $requiredByRole = [
            'lk/organization_owner' => self::CONSTRUCTION_MODULES,
            'lk/organization_admin' => self::CONSTRUCTION_MODULES,
            'project/project_manager' => self::CONSTRUCTION_MODULES,
            'project/site_engineer' => [
                'quality-control',
                'executive-documentation',
                'safety-management',
                'machinery-operations',
                'production-labor',
                'change-management',
                'handover-acceptance',
            ],
            'mobile/foreman' => [
                'quality-control',
                'safety-management',
                'machinery-operations',
                'production-labor',
                'handover-acceptance',
            ],
            'customer/customer_owner' => [
                'quality-control',
                'executive-documentation',
                'change-management',
                'handover-acceptance',
            ],
            'customer/customer_manager' => [
                'quality-control',
                'executive-documentation',
                'change-management',
                'handover-acceptance',
            ],
        ];

        foreach ($requiredByRole as $rolePath => $moduleSlugs) {
            $definition = $this->roleDefinition($rolePath);

            foreach ($moduleSlugs as $moduleSlug) {
                $this->assertArrayHasKey($moduleSlug, $definition['module_permissions'], "{$rolePath} misses {$moduleSlug}");
                $this->assertNotEmpty($definition['module_permissions'][$moduleSlug], "{$rolePath}/{$moduleSlug} has no permissions");
            }
        }
    }

    public function test_schedule_management_declares_lookahead_permissions(): void
    {
        $schedule = $this->catalog()->moduleDefinitions()['schedule-management'];

        $this->assertContains('schedule.lookahead.view', $schedule['permissions']);
        $this->assertContains('schedule.lookahead.manage', $schedule['permissions']);
        $this->assertContains('schedule.daily_plan.manage', $schedule['permissions']);
    }

    private function assertTierContains(PackageCatalogService $catalog, string $packageSlug, string $tier, array $expectedModules): void
    {
        $modules = $catalog->tierModules($packageSlug, $tier);

        foreach ($expectedModules as $moduleSlug) {
            $this->assertContains($moduleSlug, $modules, "{$packageSlug}/{$tier} misses {$moduleSlug}");
        }
    }

    private function roleDefinition(string $rolePath): array
    {
        return json_decode(
            (string) file_get_contents($this->basePath . "/config/RoleDefinitions/{$rolePath}.json"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function catalog(): PackageCatalogService
    {
        return new PackageCatalogService(
            $this->basePath . '/config/Packages',
            $this->basePath . '/config/ModuleList'
        );
    }
}
