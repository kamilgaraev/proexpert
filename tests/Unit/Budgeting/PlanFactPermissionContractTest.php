<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use PHPUnit\Framework\TestCase;

final class PlanFactPermissionContractTest extends TestCase
{
    public function test_plan_fact_permissions_are_registered_and_translated(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(
            (string) file_get_contents($root . '/config/ModuleList/features/budgeting.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $translations = require $root . '/lang/ru/permissions.php';

        $this->assertContains('budgeting.plan_fact.view', $manifest['permissions']);
        $this->assertContains('budgeting.plan_fact.export', $manifest['permissions']);
        $this->assertSame('Просмотр план-факт анализа бюджета', $translations['values']['budgeting.plan_fact.view']);
        $this->assertSame('Экспорт план-факт анализа бюджета', $translations['values']['budgeting.plan_fact.export']);
        $this->assertSame('План-факт бюджета', $translations['subjects']['budgeting.plan_fact']);
    }

    public function test_admin_roles_include_plan_fact_view_and_export_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $webAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/web_admin.json');
        $financeAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/finance_admin.json');
        $adminViewer = $this->rolePermissions($root . '/config/RoleDefinitions/admin/admin_viewer.json');

        $this->assertContains('budgeting.plan_fact.view', $webAdmin);
        $this->assertContains('budgeting.plan_fact.export', $webAdmin);
        $this->assertContains('budgeting.plan_fact.view', $financeAdmin);
        $this->assertContains('budgeting.plan_fact.export', $financeAdmin);
        $this->assertContains('budgeting.plan_fact.view', $adminViewer);
        $this->assertNotContains('budgeting.plan_fact.export', $adminViewer);
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
