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
}
