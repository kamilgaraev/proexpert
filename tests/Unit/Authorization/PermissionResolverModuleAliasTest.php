<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\PermissionResolver;
use PHPUnit\Framework\TestCase;

class PermissionResolverModuleAliasTest extends TestCase
{
    public function test_finance_alias_only_maps_its_active_module_gate_to_payments(): void
    {
        self::assertContains('payments', \App\Domain\Authorization\ValueObjects\ModulePermissionAliases::variants('finance'));
        self::assertNotContains('finance', \App\Domain\Authorization\ValueObjects\ModulePermissionAliases::variants('payments'));
    }

    public function test_finance_permissions_require_explicit_namespace_even_with_payments_wildcard(): void
    {
        $permissions = ['payments' => ['*']];

        self::assertFalse($this->financePermission($permissions, 'payments', 'finance', 'view'));
        self::assertFalse($this->financePermission($permissions, 'payments', 'finance', 'view_project_budget'));
        self::assertTrue($this->financePermission($permissions, 'payments', 'payments', 'invoice.view'));
    }

    public function test_removing_finance_namespace_revokes_its_grant_without_revoking_payments(): void
    {
        $permissions = ['payments' => ['*'], 'finance' => ['finance.view']];

        self::assertTrue($this->financePermission($permissions, 'payments', 'finance', 'view'));
        unset($permissions['finance']);
        self::assertFalse($this->financePermission($permissions, 'payments', 'finance', 'view'));
        self::assertTrue($this->financePermission($permissions, 'payments', 'payments', 'invoice.view'));
    }

    public function test_canonical_payments_storage_preserves_only_explicit_qualified_finance_grants(): void
    {
        $permissions = ['payments' => ['*', 'payments.*', 'view', 'finance.view']];
        self::assertTrue($this->financePermission($permissions, 'payments', 'finance', 'view'));
        self::assertFalse($this->financePermission($permissions, 'payments', 'finance', 'view_project_budget'));
        $permissions['payments'] = ['*', 'payments.*', 'view'];
        self::assertFalse($this->financePermission($permissions, 'payments', 'finance', 'view'));
        self::assertFalse($this->financePermission($permissions, 'payments', 'finance', 'view_project_budget'));
        self::assertTrue($this->financePermission($permissions, 'payments', 'payments', 'invoice.view'));
        self::assertTrue($this->financePermission(['payments' => ['finance.*']], 'payments', 'finance', 'view_project_budget'));
    }

    private function financePermission(array $permissions, string $module, string $requestedModule, string $action): bool
    {
        $resolver = new class($this->createMock(\App\Services\Logging\LoggingService::class)) extends PermissionResolver
        {
            public function __construct(\App\Services\Logging\LoggingService $logging)
            {
                $this->logging = $logging;
            }

            public function permits(array $permissions, string $module, string $requestedModule, string $action): bool
            {
                return $this->checkModulePermission($permissions, $module, $requestedModule, $action, $requestedModule.'.'.$action);
            }
        };

        $previousLogger = \Illuminate\Support\Facades\Log::getFacadeRoot();
        \Illuminate\Support\Facades\Log::swap(new \Psr\Log\NullLogger);
        try {
            return $resolver->permits($permissions, $module, $requestedModule, $action);
        } finally {
            if ($previousLogger === null) {
                \Illuminate\Support\Facades\Log::clearResolvedInstance('log');
            } else {
                \Illuminate\Support\Facades\Log::swap($previousLogger);
            }
        }
    }

    // Regression: ISSUE-083 — owner получал 403 на каталог спецификаций
    // Found by /qa on 2026-08-29
    // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
    public function test_specifications_permission_uses_contract_management_module_alias(): void
    {
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        $this->assertContains('contract-management', $resolver->variants('specifications'));
        $this->assertContains('contracts', $resolver->variants('contract-management'));
    }

    public function test_agreements_permission_uses_contract_management_module_alias(): void
    {
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        $this->assertContains('contract-management', $resolver->variants('agreements'));
        $this->assertContains('contracts', $resolver->variants('contract-management'));
    }

    public function test_estimate_generation_permission_uses_ai_estimates_module_alias(): void
    {
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

            public function variants(string $module): array
            {
                return $this->expandModuleVariants($module);
            }
        };

        $this->assertContains('ai-estimates', $resolver->variants('estimate_generation'));
        $this->assertContains('estimate_generation', $resolver->variants('ai-estimates'));
    }

    public function test_act_reports_permission_uses_act_reporting_module_alias(): void
    {
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
        $resolver = new class extends PermissionResolver
        {
            public function __construct() {}

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
