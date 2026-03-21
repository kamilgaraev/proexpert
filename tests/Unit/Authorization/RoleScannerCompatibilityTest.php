<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\RoleScanner;
use PHPUnit\Framework\TestCase;

class RoleScannerCompatibilityTest extends TestCase
{
    public function test_legacy_system_role_permissions_alias_returns_system_permissions(): void
    {
        $scanner = new class extends RoleScanner {
            public function getRole(string $slug): ?array
            {
                return [
                    'system_permissions' => ['admin.access', 'reports.view'],
                ];
            }
        };

        $this->assertSame(
            ['admin.access', 'reports.view'],
            $scanner->getSystemRolePermissions('organization_owner')
        );
    }
}
