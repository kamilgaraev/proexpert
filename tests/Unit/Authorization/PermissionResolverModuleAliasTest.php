<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\PermissionResolver;
use PHPUnit\Framework\TestCase;

class PermissionResolverModuleAliasTest extends TestCase
{
    public function test_act_reports_permission_uses_act_reporting_module_alias(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        $this->assertContains('act-reporting', $resolver->variants('act_reports'));
        $this->assertContains('act_reports', $resolver->variants('act-reporting'));
    }

    public function test_projects_permission_uses_project_management_module_alias(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        $this->assertContains('project-management', $resolver->variants('projects'));
        $this->assertContains('projects', $resolver->variants('project-management'));
    }

    public function test_warehouse_permission_uses_basic_warehouse_module_alias(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        $this->assertContains('basic-warehouse', $resolver->variants('warehouse'));
        $this->assertContains('warehouse', $resolver->variants('basic-warehouse'));
    }

    public function test_one_c_exchange_permission_uses_basic_exchange_module_alias(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        $this->assertContains('one-c-basic-exchange', $resolver->variants('one_c_exchange'));
        $this->assertContains('one_c_exchange', $resolver->variants('one-c-basic-exchange'));
    }

    public function test_contractor_marketplace_permission_uses_contractor_portal_module_alias(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        $this->assertContains('contractor-portal', $resolver->variants('contractor_marketplace'));
        $this->assertContains('contractor_marketplace', $resolver->variants('contractor-portal'));
    }

    public function test_admin_ai_assistant_permission_uses_ai_assistant_module_alias(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function normalize(string $module, string $action): array
            {
                return $this->normalizeAdminModulePermissionParts($module, $action);
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        [$module, $action] = $resolver->normalize('admin', 'ai_assistant.project_pulse.view');

        $this->assertSame('ai_assistant', $module);
        $this->assertSame('project_pulse.view', $action);
        $this->assertContains('ai-assistant', $resolver->variants($module));
    }

    public function test_admin_projects_permission_uses_project_management_module_alias(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function normalize(string $module, string $action): array
            {
                return $this->normalizeAdminModulePermissionParts($module, $action);
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        [$module, $action] = $resolver->normalize('admin', 'projects.view');

        $this->assertSame('projects', $module);
        $this->assertSame('view', $action);
        $this->assertContains('project-management', $resolver->variants($module));
    }

    public function test_legacy_admin_catalog_permissions_use_catalog_management_aliases(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function normalize(string $module, string $action): array
            {
                return $this->normalizeAdminModulePermissionParts($module, $action);
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        [$module, $action] = $resolver->normalize('admin', 'materials.import');

        $this->assertSame('materials', $module);
        $this->assertSame('import', $action);
        $this->assertContains('catalog-management', $resolver->variants($module));
    }

    public function test_legacy_admin_contract_and_report_permissions_use_module_aliases(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function normalize(string $module, string $action): array
            {
                return $this->normalizeAdminModulePermissionParts($module, $action);
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        [$contractModule, $contractAction] = $resolver->normalize('admin', 'contracts.view');
        [$reportModule, $reportAction] = $resolver->normalize('admin', 'reports.export');

        $this->assertSame('contracts', $contractModule);
        $this->assertSame('view', $contractAction);
        $this->assertContains('contract-management', $resolver->variants($contractModule));

        $this->assertSame('reports', $reportModule);
        $this->assertSame('export', $reportAction);
        $this->assertContains('reports', $resolver->variants($reportModule));
    }

    public function test_legacy_admin_users_permissions_use_users_module_alias(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function normalize(string $module, string $action): array
            {
                return $this->normalizeAdminModulePermissionParts($module, $action);
            }

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        [$module, $action] = $resolver->normalize('admin', 'users.view');

        $this->assertSame('users', $module);
        $this->assertSame('view', $action);
        $this->assertContains('users', $resolver->variants($module));
    }

    public function test_legacy_admin_users_system_permission_uses_manage_alias(): void
    {
        $resolver = new class extends PermissionResolver {
            public function __construct()
            {
            }

            public function systemVariants(string $permission): array
            {
                return $this->expandSystemPermissionVariants($permission);
            }
        };

        $this->assertContains('users.view', $resolver->systemVariants('admin.users.view'));
        $this->assertContains('users.manage', $resolver->systemVariants('admin.users.edit'));
        $this->assertContains('users.manage_admin', $resolver->systemVariants('admin.users.block'));
    }
}
