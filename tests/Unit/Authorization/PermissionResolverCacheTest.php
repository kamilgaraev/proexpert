<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\PermissionResolver;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PermissionResolverCacheTest extends TestCase
{
    public function test_user_permission_cache_version_increments_on_each_clear(): void
    {
        Cache::flush();
        Cache::put('user_permission_version_167', 1, 3600);

        $resolver = $this->resolver();

        $resolver->clearUserPermissionCache(167);
        $this->assertSame(2, Cache::get('user_permission_version_167'));

        $resolver->clearUserPermissionCache(167);
        $this->assertSame(3, Cache::get('user_permission_version_167'));
    }

    public function test_role_permission_cache_clear_forgets_role_keys_and_bumps_user_versions(): void
    {
        Cache::flush();
        Cache::put('custom_role_snabzenie_75', ['slug' => 'snabzenie'], 3600);
        Cache::put('custom_role_snabzenie_global', ['slug' => 'snabzenie'], 3600);
        Cache::put('system_perms_custom_snabzenie_75', ['admin.access'], 3600);
        Cache::put('module_perms_custom_snabzenie_75', ['catalog_management' => ['materials.view']], 3600);
        Cache::put('system_perms_v2_custom_snabzenie_75', ['admin.access'], 3600);
        Cache::put('module_perms_v2_custom_snabzenie_75', ['catalog_management' => ['materials.view']], 3600);
        Cache::put('user_permission_version_167', 4, 3600);

        $this->resolver()->clearRolePermissionCache(
            'snabzenie',
            UserRoleAssignment::TYPE_CUSTOM,
            75,
            [167]
        );

        $this->assertNull(Cache::get('custom_role_snabzenie_75'));
        $this->assertNull(Cache::get('custom_role_snabzenie_global'));
        $this->assertNull(Cache::get('system_perms_custom_snabzenie_75'));
        $this->assertNull(Cache::get('module_perms_custom_snabzenie_75'));
        $this->assertNull(Cache::get('system_perms_v2_custom_snabzenie_75'));
        $this->assertNull(Cache::get('module_perms_v2_custom_snabzenie_75'));
        $this->assertSame(5, Cache::get('user_permission_version_167'));
    }

    private function resolver(): PermissionResolver
    {
        return new class extends PermissionResolver {
            public function __construct()
            {
            }
        };
    }
}
