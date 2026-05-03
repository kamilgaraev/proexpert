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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class AuthorizationRoleRevocationAuditTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_role_revocation_audit_contains_actor_user(): void
    {
        $target = $this->mockUser(10, 'target@example.test');
        $actor = $this->mockUser(20, 'actor@example.test');
        $context = $this->mockContext(30);
        $assignment = $this->mockAssignment(40, true);

        $target->shouldReceive('roleAssignments')->once()->andReturn($this->mockRoleAssignmentQuery($assignment));

        $logging = Mockery::mock(LoggingService::class);
        $logging->shouldReceive('audit')
            ->once()
            ->with('auth.role.revoked', Mockery::on(static fn (array $payload): bool => (
                $payload['target_user_id'] === 10
                && $payload['target_email'] === 'target@example.test'
                && $payload['role_slug'] === 'customer_manager'
                && $payload['assignment_id'] === 40
                && $payload['revoked_by'] === 20
                && $payload['revoked_by_email'] === 'actor@example.test'
                && $payload['revoked_by_type'] === 'user'
            )));

        $service = new AuthorizationService(
            Mockery::mock(RoleScanner::class),
            Mockery::mock(PermissionResolver::class),
            $logging
        );

        $this->assertTrue($service->revokeRole($target, 'customer_manager', $context, $actor));
    }

    public function test_role_revocation_audit_keeps_system_actor_when_user_is_absent(): void
    {
        $target = $this->mockUser(11, 'target-system@example.test');
        $context = $this->mockContext(31);
        $assignment = $this->mockAssignment(41, false);

        $target->shouldReceive('roleAssignments')->once()->andReturn($this->mockRoleAssignmentQuery($assignment));

        $logging = Mockery::mock(LoggingService::class);
        $logging->shouldReceive('audit')
            ->once()
            ->with('auth.role.revoked', Mockery::on(static fn (array $payload): bool => (
                $payload['target_user_id'] === 11
                && $payload['target_email'] === 'target-system@example.test'
                && $payload['role_slug'] === 'customer_viewer'
                && $payload['assignment_id'] === 41
                && $payload['revoked_by'] === null
                && $payload['revoked_by_email'] === null
                && $payload['revoked_by_type'] === 'system'
            )));

        $service = new AuthorizationService(
            Mockery::mock(RoleScanner::class),
            Mockery::mock(PermissionResolver::class),
            $logging
        );

        $this->assertTrue($service->revokeRole($target, 'customer_viewer', $context));
    }

    private function mockUser(int $id, string $email): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setAttribute('id', $id);
        $user->setAttribute('email', $email);

        return $user;
    }

    private function mockContext(int $id): AuthorizationContext
    {
        $context = Mockery::mock(AuthorizationContext::class)->makePartial();
        $context->setAttribute('id', $id);
        $context->setAttribute('type', AuthorizationContext::TYPE_ORGANIZATION);

        return $context;
    }

    private function mockAssignment(int $id, bool $isActive): UserRoleAssignment
    {
        $assignment = Mockery::mock(UserRoleAssignment::class)->makePartial();
        $assignment->setAttribute('id', $id);
        $assignment->setAttribute('role_type', UserRoleAssignment::TYPE_SYSTEM);
        $assignment->setAttribute('is_active', $isActive);
        $assignment->shouldReceive('revoke')->once()->andReturnTrue();

        return $assignment;
    }

    private function mockRoleAssignmentQuery(UserRoleAssignment $assignment): HasMany
    {
        $query = Mockery::mock(HasMany::class);
        $query->shouldReceive('where')->with('role_slug', Mockery::type('string'))->once()->andReturnSelf();
        $query->shouldReceive('where')->with('context_id', Mockery::type('int'))->once()->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn($assignment);

        return $query;
    }
}
