<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use PHPUnit\Framework\TestCase;

final class ProjectMarginPermissionContractTest extends TestCase
{
    public function test_project_margin_permissions_are_registered_translated_and_routed(): void
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

        $this->assertContains('budgeting.project_margin.view', $manifest['permissions']);
        $this->assertContains('budgeting.project_margin.export', $manifest['permissions']);
        $this->assertSame('Просмотр отчета маржинальности проекта', $translations['values']['budgeting.project_margin.view']);
        $this->assertSame('Экспорт отчета маржинальности проекта', $translations['values']['budgeting.project_margin.export']);
        $this->assertSame('Маржинальность проекта', $translations['subjects']['budgeting.project_margin']);
        $this->assertStringContainsString('/project-margin', $routes);
        $this->assertStringContainsString('authorize:budgeting.project_margin.view', $routes);
    }

    public function test_admin_roles_include_project_margin_view_and_export_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $webAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/web_admin.json');
        $financeAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/finance_admin.json');
        $adminViewer = $this->rolePermissions($root . '/config/RoleDefinitions/admin/admin_viewer.json');

        $this->assertContains('budgeting.project_margin.view', $webAdmin);
        $this->assertContains('budgeting.project_margin.export', $webAdmin);
        $this->assertContains('budgeting.project_margin.view', $financeAdmin);
        $this->assertContains('budgeting.project_margin.export', $financeAdmin);
        $this->assertContains('budgeting.project_margin.view', $adminViewer);
        $this->assertNotContains('budgeting.project_margin.export', $adminViewer);
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
