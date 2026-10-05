<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use App\Services\Logging\LoggingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthorizationDecisionCaptureTest extends TestCase
{
    public function test_capture_records_unique_permissions_contexts_and_decisions(): void
    {
        $service = $this->service();
        $user = $this->user(7);
        $scope = $service->forCurrentChecks(true);

        $captured = $scope->captureCurrentDecisions(['ip' => '192.0.2.10'], function () use ($scope, $user): array {
            return [
                $scope->canCurrent($user, 'reports.view', ['organization_id' => 38]),
                $scope->canCurrent($user, 'reports.view', ['organization_id' => 38]),
                $scope->canCurrent($user, 'reports.edit', ['organization_id' => 38]),
            ];
        });

        self::assertSame([true, true, false], $captured['value']);
        self::assertCount(2, $captured['decisions']);
        self::assertSame(2, $service->checks());
        self::assertSame(
            ['192.0.2.10', '192.0.2.10'],
            array_column($service->contexts(), 'ip'),
        );

        self::assertSame(
            ['reports.view', 'reports.edit'],
            array_column($captured['decisions'], 'permission'),
        );
        self::assertTrue($captured['decisions'][0]['allowed']);
        self::assertFalse($captured['decisions'][1]['allowed']);
    }

    public function test_current_scope_clone_keeps_capture_observer_and_current_result(): void
    {
        $service = $this->service();
        $user = $this->user(8);

        $captured = $service->captureCurrentDecisions([], function () use ($service, $user): bool {
            $clone = $service->forCurrentChecks(true);

            return $clone->canCurrent($user, 'reports.view', ['organization_id' => 38]);
        });

        self::assertTrue($captured['value']);
        self::assertCount(1, $captured['decisions']);
        self::assertSame('reports.view', $captured['decisions'][0]['permission']);
        self::assertSame(1, $service->checks());
    }

    public function test_trusted_inputs_are_scoped_and_restored_after_exception(): void
    {
        $service = $this->service();
        $user = $this->user(9);
        $scope = $service->forCurrentChecks(true);

        try {
            $scope->captureCurrentDecisions(['ip' => '198.51.100.4'], function () use ($scope, $user): never {
                $scope->canCurrent($user, 'reports.view', ['organization_id' => 38]);
                throw new RuntimeException('capture failure');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('capture failure', $exception->getMessage());
        }

        $scope->canCurrent($user, 'reports.edit', ['organization_id' => 38]);

        self::assertCount(2, $service->contexts());
        self::assertSame('198.51.100.4', $service->contexts()[0]['ip']);
        self::assertArrayNotHasKey('ip', $service->contexts()[1]);
    }

    public function test_nested_capture_restores_outer_observer_and_trusted_context(): void
    {
        $service = $this->service();
        $user = $this->user(10);
        $scope = $service->forCurrentChecks(true);

        $outer = $scope->captureCurrentDecisions(['ip' => '203.0.113.1'], function () use ($scope, $user): array {
            $nested = $scope->captureCurrentDecisions(['ip' => '203.0.113.2'], function () use ($scope, $user): bool {
                return $scope->canCurrent($user, 'reports.edit', ['organization_id' => 38]);
            });

            $afterNested = $scope->canCurrent($user, 'reports.view', ['organization_id' => 38]);

            return [$nested, $afterNested];
        });

        self::assertFalse($outer['value'][0]['value']);
        self::assertTrue($outer['value'][1]);
        self::assertSame('203.0.113.2', $outer['value'][0]['decisions'][0]['context']['ip']);
        self::assertSame('203.0.113.1', $outer['decisions'][0]['context']['ip']);
        self::assertSame(2, $service->checks());
    }

    private function service(): CapturingAuthorizationService
    {
        $resolver = $this->createMock(PermissionResolver::class);
        $resolver->method('forCurrentChecks')->willReturnSelf();
        $resolver->method('forReadScope')->willReturnSelf();

        return new CapturingAuthorizationService(
            $this->createMock(RoleScanner::class),
            $resolver,
            $this->createMock(LoggingService::class),
        );
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['id' => $id, 'current_organization_id' => 38]);
    }
}

final class CapturingAuthorizationService extends AuthorizationService
{
    /** @var object{checks:int,contexts:list<array<string,mixed>>} */
    private object $state;

    public function __construct(RoleScanner $roleScanner, PermissionResolver $permissionResolver, LoggingService $logging)
    {
        parent::__construct($roleScanner, $permissionResolver, $logging);
        $this->state = (object) ['checks' => 0, 'contexts' => []];
    }

    protected function checkPermission(User $user, string $permission, ?array $context = null): bool
    {
        $this->state->checks++;
        $this->state->contexts[] = $context ?? [];

        return $permission === 'reports.view';
    }

    public function checks(): int
    {
        return $this->state->checks;
    }

    /** @return list<array<string,mixed>> */
    public function contexts(): array
    {
        return $this->state->contexts;
    }
}
