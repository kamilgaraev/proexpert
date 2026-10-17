<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\RoleScanner;
use App\Services\User\AdminUserRolePolicy;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class AdminUserRolePriorityTest extends TestCase
{
    public function test_every_role_definition_declares_a_bounded_primary_display_priority(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/config/RoleDefinitions'));
        $count = 0;
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }
            $role = json_decode(file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('primary_priority', $role, $file->getPathname());
            self::assertIsInt($role['primary_priority']);
            self::assertGreaterThanOrEqual(0, $role['primary_priority']);
            self::assertLessThan(100000, $role['primary_priority']);
            $count++;
        }
        self::assertGreaterThan(20, $count);
    }

    public function test_primary_priorities_come_from_catalog_metadata_instead_of_a_php_slug_list(): void
    {
        $scanner = new class extends RoleScanner
        {
            public function getAllRoles(): Collection
            {
                return collect(['ordinary' => ['primary_priority' => 90], 'new_catalog_role' => ['primary_priority' => 12],
                    'legacy_without_priority' => [], 'invalid_priority' => ['primary_priority' => -1]]);
            }
        };
        $policy = (new \ReflectionClass(AdminUserRolePolicy::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AdminUserRolePolicy::class, 'roleScanner'))->setValue($policy, $scanner);
        self::assertSame(['ordinary' => 90, 'new_catalog_role' => 12, 'legacy_without_priority' => 100000,
            'invalid_priority' => 100000], $policy->primaryRolePriorities());
    }
}
