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
use PHPUnit\Framework\TestCase;

final class AuthorizationContextReadTest extends TestCase
{
    public function test_same_lookup_reuses_context_without_merging_condition_or_strict_scope_decisions(): void
    {
        $service = $this->service();
        $scope = $service->forCurrentChecks(true);
        $user = (new User)->forceFill(['id' => 7, 'current_organization_id' => 38]);
        self::assertTrue($scope->canCurrent($user, 'projects.view', ['project_id' => 4, 'organization_id' => 38, 'condition_pass' => true]));
        self::assertFalse($scope->canCurrent($user, 'projects.view', ['organization_id' => 38, 'project_id' => 4, 'condition_pass' => false]));
        self::assertFalse($scope->canCurrent($user, 'projects.view', ['project_id' => 4, 'organization_id' => 38, 'strict_project_scope' => true]));
        self::assertSame(1, $scope->lookups());
        self::assertTrue($scope->canCurrent($user, 'projects.edit', ['project_id' => 4, 'organization_id' => 39]));
        self::assertSame(2, $scope->lookups());
        self::assertTrue($scope->canCurrent($user, 'projects.edit', ['project_id' => 5, 'organization_id' => 38]));
        self::assertSame(3, $scope->lookups());
        $fresh = $scope->forCurrentChecks(true);
        self::assertTrue($fresh->canCurrent($user, 'projects.view', ['project_id' => 4, 'organization_id' => 38]));
        self::assertSame(4, $fresh->lookups());
    }

    public function test_role_slugs_and_permissions_share_the_org_lookup_with_extra_trusted_inputs(): void
    {
        $scope = $this->service()->forCurrentChecks(true);
        $user = (new User)->forceFill(['id' => 8, 'current_organization_id' => 38]);
        self::assertTrue($scope->canCurrent($user, 'reports.view', ['ip' => '192.0.2.10', 'organization_id' => 38]));
        self::assertSame(['test-role'], $scope->getUserRoleSlugs($user, ['organization_id' => 38]));
        self::assertSame(1, $scope->lookups());
    }

    private function service(): CountingContextAuthorizationService
    {
        $resolver = $this->createMock(PermissionResolver::class);
        $resolver->method('forCurrentChecks')->willReturn($resolver);
        $resolver->method('forReadScope')->willReturn($resolver);
        $resolver->method('hasPermission')->willReturn(true);

        return new CountingContextAuthorizationService($this->createMock(RoleScanner::class), $resolver, $this->createMock(LoggingService::class));
    }
}

final class CountingContextAuthorizationService extends AuthorizationService
{
    private object $counter;

    public function __construct(RoleScanner $scanner, PermissionResolver $resolver, LoggingService $logging)
    {
        parent::__construct($scanner, $resolver, $logging);
        $this->counter = (object) ['lookups' => 0];
    }

    protected function resolveAuthContext(?array $context): ?AuthorizationContext
    {
        $this->counter->lookups++;

        return (new AuthorizationContext)->forceFill(['id' => 100 + ($context['project_id'] ?? 0),
            'type' => isset($context['project_id']) ? AuthorizationContext::TYPE_PROJECT : AuthorizationContext::TYPE_ORGANIZATION,
            'resource_id' => $context['project_id'] ?? $context['organization_id'] ?? null]);
    }

    public function getUserRoles(User $user, ?AuthorizationContext $context = null): Collection
    {
        return collect([(new UserRoleAssignment)->forceFill(['id' => 1, 'context_id' => 999, 'role_slug' => 'test-role', 'role_type' => 'system'])]);
    }

    protected function getContextHierarchy(AuthorizationContext $context): Collection { return collect([$context]); }
    protected function evaluateConditions(UserRoleAssignment $assignment, array $context): bool { return $context['condition_pass'] ?? true; }
    public function lookups(): int { return $this->counter->lookups; }
}
