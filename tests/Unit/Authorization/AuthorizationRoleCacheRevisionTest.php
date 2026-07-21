<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;

final class AuthorizationRoleCacheRevisionTest extends TestCase
{
    public function test_role_cache_reset_revisions_all_permission_cache_layers(): void
    {
        $root = dirname(__DIR__, 3);
        $scanner = file_get_contents($root.'/app/Domain/Authorization/Services/RoleScanner.php');
        $resolver = file_get_contents($root.'/app/Domain/Authorization/Services/PermissionResolver.php');

        self::assertIsString($scanner);
        self::assertIsString($resolver);
        self::assertStringContainsString("Cache::increment(self::CACHE_REVISION_KEY);", $scanner);
        self::assertSame(3, substr_count($resolver, "Cache::get('authorization_roles_revision', 0)"));
        self::assertStringContainsString('_r{$roleRevision}_', $resolver);
    }
}
