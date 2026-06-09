<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use PHPUnit\Framework\TestCase;

final class ProjectPortfolioDashboardPermissionContractTest extends TestCase
{
    public function test_project_portfolio_dashboard_permission_is_registered_translated_and_routed(): void
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

        $this->assertContains('budgeting.portfolio_dashboard.view', $manifest['permissions']);
        $this->assertSame('Просмотр портфельного дашборда проектов', $translations['values']['budgeting.portfolio_dashboard.view']);
        $this->assertSame('Портфельный дашборд проектов', $translations['subjects']['budgeting.portfolio_dashboard']);
        $this->assertStringContainsString('/project-portfolio-dashboard', $routes);
        $this->assertStringContainsString('authorize:budgeting.portfolio_dashboard.view', $routes);
    }

    public function test_admin_roles_include_project_portfolio_dashboard_view_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $webAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/web_admin.json');
        $financeAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/finance_admin.json');
        $adminViewer = $this->rolePermissions($root . '/config/RoleDefinitions/admin/admin_viewer.json');

        $this->assertContains('budgeting.portfolio_dashboard.view', $webAdmin);
        $this->assertContains('budgeting.portfolio_dashboard.view', $financeAdmin);
        $this->assertContains('budgeting.portfolio_dashboard.view', $adminViewer);
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
