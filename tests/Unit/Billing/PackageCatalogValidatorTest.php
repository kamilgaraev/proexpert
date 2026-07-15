<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Enums\ModuleDevelopmentStatus;
use App\Services\Modules\PackageCatalogService;
use App\Services\Modules\PackageCatalogValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PackageCatalogValidatorTest extends TestCase
{
    private string $basePath;
    private string $temporaryPackagesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = dirname(__DIR__, 3);
        $this->temporaryPackagesPath = sys_get_temp_dir() . '/most-package-catalog-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryPackagesPath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryPackagesPath . '/*.json') ?: [] as $filePath) {
            unlink($filePath);
        }

        rmdir($this->temporaryPackagesPath);

        parent::tearDown();
    }

    public function test_package_catalog_exposes_single_standard_commercial_variant(): void
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
            $this->assertSame(['standard'], array_keys($package['tiers']));

            $standard = $package['tiers']['standard'];
            $this->assertNotEmpty($standard['included_modules']);
            $this->assertSame($standard['modules'], $standard['included_modules']);
        }
    }

    public function test_current_package_catalog_has_no_integrity_errors(): void
    {
        $result = $this->validator()->validate();

        $this->assertSame([], $result['errors']);
    }

    public function test_catalog_service_rejects_standard_combined_with_base(): void
    {
        $this->assertCatalogServiceRejectsTiers([
            'standard' => ['modules' => []],
            'base' => ['modules' => []],
        ]);
    }

    public function test_catalog_service_rejects_legacy_unknown_and_missing_standard_tiers(): void
    {
        foreach (['pro', 'enterprise', 'unknown'] as $tierKey) {
            $this->assertCatalogServiceRejectsTiers([
                $tierKey => ['modules' => []],
            ]);
        }

        $this->assertCatalogServiceRejectsTiers([]);
    }

    public function test_validator_rejects_any_tier_set_other_than_standard_only(): void
    {
        $invalidTierSets = [
            [
                'standard' => ['modules' => []],
                'base' => ['modules' => []],
            ],
            ['pro' => ['modules' => []]],
            ['enterprise' => ['modules' => []]],
            ['unknown' => ['modules' => []]],
            [],
        ];

        foreach ($invalidTierSets as $tiers) {
            $result = $this->validator()->validate(
                [['slug' => 'broken-package', 'tiers' => $tiers]],
                [],
                [],
                []
            );

            $this->assertNotEmpty($result['errors']);
            $this->assertStringContainsString('standard', $result['errors'][0]);
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
                    'standard' => [
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
                    'standard' => [
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
                    'standard' => [
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

    private function assertCatalogServiceRejectsTiers(array $tiers): void
    {
        file_put_contents(
            $this->temporaryPackagesPath . '/broken-package.json',
            json_encode([
                'slug' => 'broken-package',
                'tiers' => $tiers,
            ], JSON_THROW_ON_ERROR)
        );

        $catalog = new PackageCatalogService(
            $this->temporaryPackagesPath,
            $this->basePath . '/config/ModuleList'
        );

        $exception = null;

        try {
            $catalog->allPackages();
        } catch (RuntimeException $caughtException) {
            $exception = $caughtException;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('standard', $exception->getMessage());
    }
}
