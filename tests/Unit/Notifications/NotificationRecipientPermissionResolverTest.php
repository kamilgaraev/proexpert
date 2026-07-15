<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Services\NotificationRecipientPermissionResolver;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use PHPUnit\Framework\TestCase;

final class NotificationRecipientPermissionResolverTest extends TestCase
{
    public function test_resolves_receive_permissions_from_notification_domain(): void
    {
        $resolver = new NotificationRecipientPermissionResolver($this->createMock(AuthorizationService::class));

        self::assertSame(
            ['notifications.receive.site_requests'],
            $resolver->requiredPermissions('site_request_created', 'site_requests', [])
        );
        self::assertSame(
            ['notifications.receive.procurement'],
            $resolver->requiredPermissions('procurement.purchase_order.sent', 'procurement', [])
        );
        self::assertSame(
            ['notifications.receive.system'],
            $resolver->requiredPermissions('contract_status_changed', 'system', [])
        );
    }

    public function test_explicit_required_permissions_override_domain_mapping(): void
    {
        $resolver = new NotificationRecipientPermissionResolver($this->createMock(AuthorizationService::class));

        self::assertSame(
            ['notifications.receive.procurement', 'notifications.receive.site_requests'],
            $resolver->requiredPermissions(
                'site_request_created',
                'site_requests',
                ['required_permissions' => ['notifications.receive.procurement', 'notifications.receive.site_requests']]
            )
        );
    }

    public function test_explicit_empty_permissions_disable_domain_inference(): void
    {
        $resolver = new NotificationRecipientPermissionResolver($this->createMock(AuthorizationService::class));

        self::assertSame(
            [],
            $resolver->requiredPermissions('system.test', 'system_admin_broadcast', [], [])
        );
    }

    public function test_can_receive_checks_authorization_with_project_context(): void
    {
        $user = new User;
        $user->forceFill([
            'id' => 7,
            'current_organization_id' => 11,
        ]);

        $authorization = $this->createMock(AuthorizationService::class);
        $authorization
            ->expects(self::once())
            ->method('can')
            ->with(
                self::identicalTo($user),
                'notifications.receive.site_requests',
                ['organization_id' => 11, 'project_id' => 55]
            )
            ->willReturn(true);

        $resolver = new NotificationRecipientPermissionResolver($authorization);

        self::assertTrue($resolver->canReceive(
            $user,
            ['notifications.receive.site_requests'],
            11,
            ['project_id' => 55]
        ));
    }

    public function test_denies_when_permission_context_is_missing(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization
            ->expects(self::never())
            ->method('can');

        $resolver = new NotificationRecipientPermissionResolver($authorization);

        self::assertFalse($resolver->canReceive(new User, ['notifications.receive.system'], null, []));
    }
}
