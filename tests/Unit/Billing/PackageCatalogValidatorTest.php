<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Enums\ModuleDevelopmentStatus;
use App\Services\Modules\PackageCatalogService;
use App\Services\Modules\PackageCatalogValidator;
use PHPUnit\Framework\TestCase;

class PackageCatalogValidatorTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = dirname(__DIR__, 3);
    }

    public function test_package_catalog_exposes_v2_contract_for_all_packages(): void
    {
        $packages = $this->catalog()->allPackages();

        $this->assertCount(10, $packages);

        foreach ($packages as $package) {
            $this->assertSame(2, $package['schema_version']);
            $this->assertNotEmpty($package['foundation_modules']);
            $this->assertArrayHasKey('integrations', $package);
            $this->assertArrayHasKey('recommended_addons', $package);
            $this->assertArrayHasKey('business_outcomes', $package);
            $this->assertArrayHasKey('capabilities', $package);

            foreach ($package['tiers'] as $tier) {
                $this->assertNotEmpty($tier['included_modules']);
                $this->assertSame($tier['modules'], $tier['included_modules']);
            }
        }
    }

    public function test_current_package_catalog_has_no_integrity_errors(): void
    {
        $result = $this->validator()->validate();

        $this->assertSame([], $result['errors']);
    }

    public function test_one_c_basic_exchange_is_included_in_every_package_tier(): void
    {
        foreach ($this->catalog()->allPackages() as $package) {
            foreach ($package['tiers'] as $tierKey => $tier) {
                $this->assertContains(
                    'one-c-basic-exchange',
                    $tier['included_modules'],
                    "{$package['slug']}/{$tierKey} must include 1C basic exchange"
                );
            }
        }
    }

    public function test_all_module_development_statuses_are_valid_enum_values(): void
    {
        $validStatuses = array_map(
            static fn (ModuleDevelopmentStatus $status): string => $status->value,
            ModuleDevelopmentStatus::cases()
        );

        foreach ($this->catalog()->moduleDefinitions() as $moduleSlug => $module) {
            $this->assertContains(
                $module['development_status'] ?? 'stable',
                $validStatuses,
                "{$moduleSlug} has invalid development_status"
            );
        }
    }

    public function test_legacy_active_module_development_status_falls_back_to_stable(): void
    {
        $module = new \App\Models\Module();
        $module->setRawAttributes([
            'development_status' => 'active',
        ]);

        $this->assertSame(
            ModuleDevelopmentStatus::STABLE,
            $module->getDevelopmentStatusEnum()
        );
    }

    public function test_validator_rejects_unknown_module_slug(): void
    {
        $result = $this->validator()->validate(
            [[
                'slug' => 'broken-package',
                'tiers' => [
                    'base' => [
                        'modules' => ['missing-module'],
                    ],
                ],
            ]],
            [
                'organizations' => ['slug' => 'organizations'],
            ],
            ['organizations'],
            []
        );

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('missing-module', $result['errors'][0]);
    }

    public function test_validator_rejects_unclosed_dependency_outside_foundation(): void
    {
        $result = $this->validator()->validate(
            [[
                'slug' => 'broken-package',
                'tiers' => [
                    'base' => [
                        'modules' => ['child-module'],
                    ],
                ],
            ]],
            [
                'child-module' => [
                    'slug' => 'child-module',
                    'dependencies' => ['parent-module'],
                ],
                'parent-module' => [
                    'slug' => 'parent-module',
                    'dependencies' => [],
                ],
            ],
            [],
            [
                'child-module' => 'package',
                'parent-module' => 'package',
            ]
        );

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('parent-module', $result['errors'][0]);
    }

    public function test_validator_rejects_unclassified_visible_module(): void
    {
        $result = $this->validator()->validate(
            [[
                'slug' => 'empty-package',
                'tiers' => [
                    'base' => [
                        'modules' => [],
                    ],
                ],
            ]],
            [
                'visible-addon' => [
                    'slug' => 'visible-addon',
                    'billing_model' => 'subscription',
                    'dependencies' => [],
                ],
            ],
            [],
            []
        );

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('visible-addon', $result['errors'][0]);
    }

    private function catalog(): PackageCatalogService
    {
        return new PackageCatalogService(
            $this->basePath . '/config/Packages',
            $this->basePath . '/config/ModuleList'
        );
    }

    private function validator(): PackageCatalogValidator
    {
        return new PackageCatalogValidator($this->catalog());
    }
}
