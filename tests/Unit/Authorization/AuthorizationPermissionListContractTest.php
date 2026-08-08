<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

final class AuthorizationPermissionListContractTest extends TestCase
{
    public function test_permissions_remain_a_list_when_roles_have_duplicate_permissions(): void
    {
        $first = new UserRoleAssignment(['role_slug' => 'first', 'role_type' => 'system']);
        $second = new UserRoleAssignment(['role_slug' => 'second', 'role_type' => 'system']);
        $service = new class(collect([$first, $second])) extends AuthorizationService
        {
            public function __construct(private readonly Collection $assignments)
            {
                $permissions = Mockery::mock(PermissionResolver::class);
                $permissions->shouldReceive('extractOrganizationId')->twice()->andReturn(null);
                parent::__construct(
                    Mockery::mock(RoleScanner::class),
                    $permissions,
                    Mockery::mock(LoggingService::class),
                );
            }

            public function getUserRoles(User $user, ?AuthorizationContext $context = null): Collection
            {
                return $this->assignments;
            }

            protected function getRolePermissions(string $roleSlug, string $roleType, ?int $organizationId = null): array
            {
                return $roleSlug === 'first'
                    ? ['workforce.view', 'reports.view']
                    : ['workforce.view', 'workforce.reports.export'];
            }
        };

        $permissions = $service->getUserPermissions(new User());

        self::assertTrue(array_is_list($permissions));
        self::assertSame(
            ['workforce.view', 'reports.view', 'workforce.reports.export'],
            $permissions,
        );
    }
}
