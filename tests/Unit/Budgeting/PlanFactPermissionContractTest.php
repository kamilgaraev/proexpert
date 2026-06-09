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

    public function test_period_close_status_permission_is_registered_translated_and_assigned(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(
            (string) file_get_contents($root . '/config/ModuleList/features/budgeting.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $translations = require $root . '/lang/ru/permissions.php';
        $webAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/web_admin.json');
        $financeAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/finance_admin.json');
        $adminViewer = $this->rolePermissions($root . '/config/RoleDefinitions/admin/admin_viewer.json');

        $this->assertContains('budgeting.periods.close_status.view', $manifest['permissions']);
        $this->assertSame(
            'Просмотр статуса закрытия бюджетных периодов',
            $translations['values']['budgeting.periods.close_status.view']
        );
        $this->assertContains('budgeting.periods.close_status.view', $webAdmin);
        $this->assertContains('budgeting.periods.close_status.view', $financeAdmin);
        $this->assertContains('budgeting.periods.close_status.view', $adminViewer);
        $this->assertNotContains('budgeting.periods.close', $adminViewer);
    }

    public function test_period_reopen_permission_is_registered_translated_and_assigned(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(
            (string) file_get_contents($root . '/config/ModuleList/features/budgeting.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $translations = require $root . '/lang/ru/permissions.php';
        $webAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/web_admin.json');
        $financeAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/finance_admin.json');
        $adminViewer = $this->rolePermissions($root . '/config/RoleDefinitions/admin/admin_viewer.json');

        $this->assertContains('budgeting.periods.reopen', $manifest['permissions']);
        $this->assertSame(
            'Открытие бюджетных периодов для корректировок',
            $translations['values']['budgeting.periods.reopen']
        );
        $this->assertContains('budgeting.periods.reopen', $webAdmin);
        $this->assertContains('budgeting.periods.reopen', $financeAdmin);
        $this->assertNotContains('budgeting.periods.reopen', $adminViewer);
    }

    public function test_cfo_command_center_reuses_translated_cfo_view_permission(): void
    {
        $root = dirname(__DIR__, 3);
        $routes = (string) file_get_contents($root . '/app/BusinessModules/Features/Budgeting/routes.php');
        $manifest = json_decode(
            (string) file_get_contents($root . '/config/ModuleList/features/budgeting.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $translations = require $root . '/lang/ru/permissions.php';
        $financeAdmin = $this->rolePermissions($root . '/config/RoleDefinitions/admin/finance_admin.json');
        $adminViewer = $this->rolePermissions($root . '/config/RoleDefinitions/admin/admin_viewer.json');

        $this->assertStringContainsString('/cfo-command-center', $routes);
        $this->assertStringContainsString('authorize:budgeting.cfo.view', $routes);
        $this->assertContains('budgeting.cfo.view', $manifest['permissions']);
        $this->assertSame('Просмотр ЦФО', $translations['values']['budgeting.cfo.view']);
        $this->assertContains('budgeting.cfo.view', $financeAdmin);
        $this->assertContains('budgeting.cfo.view', $adminViewer);
    }

    public function test_cfo_command_center_approval_role_labels_are_readable(): void
    {
        $root = dirname(__DIR__, 3);
        $translations = require $root . '/lang/ru/budgeting.php';
        $roles = $translations['cfo_command_center']['approval_roles'] ?? [];

        $this->assertSame('Финансовый директор', $roles['financial_director'] ?? null);
        $this->assertSame('Главный бухгалтер', $roles['chief_accountant'] ?? null);
        $this->assertSame('Участник согласования', $roles['unknown'] ?? null);
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
