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

    public function test_current_role_slugs_reuse_only_an_explicit_current_read_scope(): void
    {
        $service = $this->service();
        $user = (new User)->forceFill(['id' => 7, 'current_organization_id' => 38]);
        $context = ['organization_id' => 38];
        $scope = $service->forCurrentChecks(true);
        self::assertSame(['test-role'], $scope->getCurrentUserRoleSlugs($user, $context));
        $scope->setRoleSlugs([]);
        self::assertSame(['test-role'], $scope->getCurrentUserRoleSlugs($user, $context));
        self::assertSame(1, $scope->roleReads());
        self::assertSame([], $scope->forCurrentChecks(true)->getCurrentUserRoleSlugs($user, $context));
        self::assertSame(2, $scope->roleReads());
        self::assertSame([], $service->getCurrentUserRoleSlugs($user, $context));
        $service->setRoleSlugs(['changed-role']);
        self::assertSame(['changed-role'], $service->getCurrentUserRoleSlugs($user, $context));
        self::assertSame(4, $service->roleReads());
        $plain = $service->forCurrentChecks();
        self::assertSame(['changed-role'], $plain->getCurrentUserRoleSlugs($user, $context));
        $plain->setRoleSlugs([]);
        self::assertSame([], $plain->getCurrentUserRoleSlugs($user, $context));
        self::assertSame(6, $plain->roleReads());
    }

    public function test_current_role_slug_cache_separates_actors_and_organizations(): void
    {
        $scope = $this->service()->forCurrentChecks(true);
        $first = (new User)->forceFill(['id' => 7, 'current_organization_id' => 38]);
        $second = (new User)->forceFill(['id' => 8, 'current_organization_id' => 38]);
        self::assertSame(['test-role'], $scope->getCurrentUserRoleSlugs($first, ['organization_id' => 38]));
        $scope->setRoleSlugs(['second-role']);
        self::assertSame(['second-role'], $scope->getCurrentUserRoleSlugs($second, ['organization_id' => 38]));
        $scope->setRoleSlugs([]);
        self::assertSame([], $scope->getCurrentUserRoleSlugs($first, ['organization_id' => 39]));
        self::assertSame(['test-role'], $scope->getCurrentUserRoleSlugs($first, ['organization_id' => 38]));
        self::assertSame(['second-role'], $scope->getCurrentUserRoleSlugs($second, ['organization_id' => 38]));
        self::assertSame(3, $scope->roleReads());
        self::assertSame(2, $scope->lookups());
    }

    private function service(): CountingContextAuthorizationService
    {
        $resolver = $this->createMock(PermissionResolver::class);
        $resolver->method('forCurrentChecks')->willReturn($resolver);
        $resolver->method('forReadScope')->willReturn($resolver);
        $resolver->method('hasPermission')->willReturnCallback(static fn (UserRoleAssignment $assignment, string $permission, ?array $context = null): bool => ! ($context['strict_project_scope'] ?? false));

        return new CountingContextAuthorizationService($this->createMock(RoleScanner::class), $resolver, $this->createMock(LoggingService::class));
    }
}

final class CountingContextAuthorizationService extends AuthorizationService
{
    private object $counter;

    public function __construct(RoleScanner $scanner, PermissionResolver $resolver, LoggingService $logging)
    {
        parent::__construct($scanner, $resolver, $logging);
        $this->counter = (object) ['lookups' => 0, 'role_reads' => 0, 'role_slugs' => ['test-role']];
    }

    protected function resolveAuthContext(?array $context): ?AuthorizationContext
    {
        $this->counter->lookups++;

        return (new AuthorizationContext)->forceFill(['id' => isset($context['project_id']) ? 100_000 + $context['project_id'] : 100 + ($context['organization_id'] ?? 0),
            'type' => isset($context['project_id']) ? AuthorizationContext::TYPE_PROJECT : AuthorizationContext::TYPE_ORGANIZATION,
            'resource_id' => $context['project_id'] ?? $context['organization_id'] ?? null]);
    }

    public function getUserRoles(User $user, ?AuthorizationContext $context = null): Collection
    {
        $this->counter->role_reads++;

        return collect($this->counter->role_slugs)->map(static fn (string $slug): UserRoleAssignment =>
            (new UserRoleAssignment)->forceFill(['id' => 1, 'context_id' => $context?->id ?? 999, 'role_slug' => $slug, 'role_type' => 'system']));
    }

    protected function getContextHierarchy(AuthorizationContext $context): Collection { return collect([$context]); }
    protected function evaluateConditions(UserRoleAssignment $assignment, array $context): bool { return $context['condition_pass'] ?? true; }
    public function lookups(): int { return $this->counter->lookups; }
    public function roleReads(): int { return $this->counter->role_reads; }
    public function setRoleSlugs(array $slugs): void { $this->counter->role_slugs = $slugs; }
}
