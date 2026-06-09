<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use PHPUnit\Framework\TestCase;

final class WipForecastPermissionContractTest extends TestCase
{
    private const PERMISSIONS = [
        'budgeting.wip_forecast.view',
        'budgeting.wip_forecast.view_sensitive_costs',
        'budgeting.wip_forecast.create_version',
        'budgeting.wip_forecast.update_version',
        'budgeting.wip_forecast.submit_version',
        'budgeting.wip_forecast.approve_version',
        'budgeting.wip_forecast.activate_version',
        'budgeting.wip_forecast.manage_adjustments',
        'budgeting.wip_forecast.export',
        'budgeting.wip_forecast.view_audit',
    ];

    public function test_wip_forecast_permissions_are_registered_translated_and_routed(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(
            (string) file_get_contents($root . '/config/ModuleList/features/budgeting.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $translations = require $root . '/lang/ru/permissions.php';
        $routes = (string) file_get_contents($root . '/app/BusinessModules/Features/Budgeting/routes.php');

        foreach (self::PERMISSIONS as $permission) {
            $this->assertContains($permission, $manifest['permissions']);
            $this->assertArrayHasKey($permission, $translations['values']);
        }

        $this->assertNotContains('budgeting.wip_forecast.manage', $manifest['permissions']);
        $this->assertNotContains('budgeting.wip_forecast.adjust', $manifest['permissions']);
        $this->assertSame('Прогноз завершения проекта', $translations['subjects']['budgeting.wip_forecast']);
        $this->assertStringContainsString('/wip-forecast', $routes);
        $this->assertStringContainsString('/wip-forecast/versions/{versionUuid}/audit', $routes);
        $this->assertStringContainsString('authorize:budgeting.wip_forecast.approve_version', $routes);
        $this->assertStringContainsString('authorize:budgeting.wip_forecast.view_audit', $routes);
    }

    public function test_admin_roles_include_wip_forecast_permissions_by_responsibility(): void
    {
        $root = dirname(__DIR__, 3);
        $webAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/web_admin.json');
        $financeAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/finance_admin.json');
        $adminViewer = $this->rolePermissions($root . '/config/RoleDefinitions/admin/admin_viewer.json');

        foreach (self::PERMISSIONS as $permission) {
            $this->assertContains($permission, $webAdmin);
            $this->assertContains($permission, $financeAdmin);
        }

        $this->assertContains('budgeting.wip_forecast.view', $adminViewer);
        $this->assertNotContains('budgeting.wip_forecast.view_sensitive_costs', $adminViewer);
        $this->assertNotContains('budgeting.wip_forecast.create_version', $adminViewer);
        $this->assertNotContains('budgeting.wip_forecast.manage_adjustments', $adminViewer);
    }

    private function rolePermissions(string $path): array
    {
        $role = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $permissions = [];

        foreach (($role['module_permissions'] ?? []) as $modulePermissions) {
            if (is_array($modulePermissions)) {
                $permissions = array_merge($permissions, $modulePermissions);
            }
        }

        return $permissions;
    }
}
