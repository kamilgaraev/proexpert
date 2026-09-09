<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\CustomRoleService;
use App\Domain\Authorization\Services\RoleScanner;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class CustomRoleLegalArchivePermissionsTest extends TestCase
{
    public function test_archive_permissions_are_assignable_without_activating_a_module(): void
    {
        $definition = json_decode((string) file_get_contents(__DIR__.'/../../../config/RoleDefinitions/lk/organization_owner.json'), true, flags: JSON_THROW_ON_ERROR);
        $scanner = $this->createMock(RoleScanner::class);
        $scanner->expects(self::atLeastOnce())->method('getSystemPermissions')
            ->with('organization_owner')->willReturn($definition['system_permissions']);
        $service = $this->getMockBuilder(CustomRoleService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        (new ReflectionProperty(CustomRoleService::class, 'roleScanner'))->setValue($service, $scanner);

        $permissions = $service->getAvailableSystemPermissions(77);
        self::assertArrayHasKey('legal_archive.view', $permissions);
        self::assertArrayHasKey('legal_archive.create', $permissions);
        self::assertArrayHasKey('legal_archive.settings.manage', $permissions);
        self::assertArrayNotHasKey('*', $permissions);
        self::assertArrayNotHasKey('billing.manage', $permissions);

        $validate = new \ReflectionMethod(CustomRoleService::class, 'validatePermissions');
        $validate->invoke($service, 77, ['legal_archive.view', 'legal_archive.create'], []);
        self::assertArrayHasKey('users.view', $permissions);

        $translations = require __DIR__.'/../../../lang/ru/permissions.php';
        foreach (array_keys($permissions) as $permission) {
            if (str_starts_with($permission, 'legal_archive.')) {
                self::assertNotEmpty($translations['values'][$permission] ?? null, $permission);
            }
        }
    }
}
