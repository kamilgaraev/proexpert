<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use PHPUnit\Framework\TestCase;

final class WorkforceManagementPackageTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = dirname(__DIR__, 3);
    }

    public function test_workforce_management_exposes_start_pro_and_corporate_levels(): void
    {
        $manifest = $this->workforceManifest();

        $this->assertSame('workforce-management', $manifest['slug']);
        $this->assertSame(
            'App\\BusinessModules\\Features\\WorkforceManagement\\WorkforceManagementModule',
            $manifest['class_name'],
        );
        $this->assertSame('Персонал и трудозатраты', $manifest['name']);
        $this->assertSame(['start', 'pro', 'corporate'], array_keys($manifest['tariff_levels']));
        $this->assertSame('package', $this->moduleClassifications()['workforce-management'] ?? null);
        $this->assertTrue(class_exists($manifest['class_name']));
        $this->assertStringContainsString('Единый раздел', $manifest['description']);
        $this->assertSame([], $this->permissionsWithPrefix($manifest['permissions'], 'workforce-management.'));
    }

    public function test_workforce_tariff_capabilities_and_permissions_are_cumulative(): void
    {
        $levels = $this->workforceManifest()['tariff_levels'];

        $startCapabilities = $levels['start']['capabilities'];
        $proCapabilities = $levels['pro']['capabilities'];
        $corporateCapabilities = $levels['corporate']['capabilities'];
        $startPermissions = $levels['start']['permissions'];
        $proPermissions = $levels['pro']['permissions'];
        $corporatePermissions = $levels['corporate']['permissions'];

        foreach ($startCapabilities as $capability) {
            $this->assertContains($capability, $proCapabilities, "pro misses start capability {$capability}");
            $this->assertContains($capability, $corporateCapabilities, "corporate misses start capability {$capability}");
        }

        foreach ($proCapabilities as $capability) {
            $this->assertContains($capability, $corporateCapabilities, "corporate misses pro capability {$capability}");
        }

        foreach ($startPermissions as $permission) {
            $this->assertContains($permission, $proPermissions, "pro misses start permission {$permission}");
            $this->assertContains($permission, $corporatePermissions, "corporate misses start permission {$permission}");
        }

        foreach ($proPermissions as $permission) {
            $this->assertContains($permission, $corporatePermissions, "corporate misses pro permission {$permission}");
        }
    }

    public function test_workforce_permissions_use_workforce_prefix(): void
    {
        $manifest = $this->workforceManifest();

        $expectedPermissions = [
            'workforce.view',
            'workforce.employees.basic',
            'workforce.production-labor.view',
            'workforce.production-labor.manage',
            'workforce.production-labor.approve',
            'workforce.hr.manage',
            'workforce.structure.manage',
            'workforce.attendance.manage',
            'workforce.payroll-source.manage',
            'workforce.payroll-source.validate',
            'workforce.payroll-source.lock',
            'workforce.exports.generate',
            'workforce.exports.approve',
            'workforce.settings.manage',
        ];

        foreach ($expectedPermissions as $permission) {
            $this->assertContains($permission, $manifest['permissions']);
        }

        $this->assertSame([], $this->permissionsWithPrefix($manifest['permissions'], 'workforce-management.'));

        foreach ($manifest['tariff_levels'] as $level) {
            $this->assertSame([], $this->permissionsWithPrefix($level['permissions'], 'workforce-management.'));
        }
    }

    public function test_production_labor_remains_alias_with_legacy_permissions(): void
    {
        $manifest = $this->moduleManifest('production-labor');

        $this->assertSame('workforce-management', $manifest['parent_module']);
        $this->assertSame('alias', $manifest['package_role']);
        $this->assertSame(['start', 'pro', 'corporate'], $manifest['included_in_workforce_levels']);

        $this->assertSame(
            'App\\BusinessModules\\Features\\ProductionLabor\\ProductionLaborModule',
            $manifest['class_name'],
        );
        $this->assertContains('production-labor.view', $manifest['permissions']);
        $this->assertContains('production-labor.payroll.approve', $manifest['permissions']);
    }

    public function test_admin_roles_do_not_use_removed_workforce_management_permissions(): void
    {
        foreach (['admin_viewer', 'web_admin'] as $role) {
            $permissions = $this->roleManifest($role)['module_permissions']['workforce-management'];

            $this->assertContains('workforce.view', $permissions);
            $this->assertSame([], $this->permissionsWithPrefix($permissions, 'workforce-management.'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workforceManifest(): array
    {
        return $this->moduleManifest('workforce-management');
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleManifest(string $slug): array
    {
        return json_decode(
            (string) file_get_contents($this->basePath . "/config/ModuleList/features/{$slug}.json"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function roleManifest(string $slug): array
    {
        return json_decode(
            (string) file_get_contents($this->basePath . "/config/RoleDefinitions/admin/{$slug}.json"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, string>
     */
    private function moduleClassifications(): array
    {
        $config = require $this->basePath . '/config/module_packages.php';

        return $config['module_classifications'] ?? [];
    }

    /**
     * @param array<int, string> $permissions
     *
     * @return array<int, string>
     */
    private function permissionsWithPrefix(array $permissions, string $prefix): array
    {
        return array_values(array_filter(
            $permissions,
            static fn (string $permission): bool => str_starts_with($permission, $prefix),
        ));
    }
}
