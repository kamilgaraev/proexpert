<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Domain\Authorization\ValueObjects\PermissionSet;
use PHPUnit\Framework\TestCase;

class ActivityEventPermissionTest extends TestCase
{
    public function test_organization_owner_system_logs_wildcard_allows_activity_events(): void
    {
        $role = json_decode((string) file_get_contents(__DIR__ . '/../../../config/RoleDefinitions/lk/organization_owner.json'), true);
        $permissions = PermissionSet::fromJsonRole($role);

        $this->assertTrue($permissions->hasPermission('system-logs.activity-events.view'));
        $this->assertTrue($permissions->hasPermission('system-logs.activity-events.export'));
    }

    public function test_organization_admin_activity_events_are_scoped_to_system_logs_module(): void
    {
        $role = json_decode((string) file_get_contents(__DIR__ . '/../../../config/RoleDefinitions/lk/organization_admin.json'), true);
        $permissions = PermissionSet::fromJsonRole($role);

        $this->assertTrue($permissions->hasPermission('system-logs.activity-events.view'));
        $this->assertTrue($permissions->hasPermission('system-logs.activity-events.export'));
    }
}
