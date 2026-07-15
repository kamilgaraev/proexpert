<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Services\NotificationRequestInterfaceResolver;
use DomainException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationRequestInterfaceResolverTest extends TestCase
{
    #[DataProvider('trustedNotificationRoutes')]
    public function test_resolves_interface_from_trusted_route_prefix(string $uri, NotificationInterface $expected): void
    {
        $request = Request::create($uri, 'GET');

        self::assertSame($expected, (new NotificationRequestInterfaceResolver)->resolve($request));
    }

    public static function trustedNotificationRoutes(): array
    {
        return [
            'admin' => ['/api/v1/admin/notifications', NotificationInterface::Admin],
            'landing' => ['/api/v1/landing/notifications', NotificationInterface::Lk],
            'mobile' => ['/api/v1/mobile/notifications', NotificationInterface::Mobile],
            'customer' => ['/api/v1/customer/notifications', NotificationInterface::Customer],
            'legacy customer' => ['/api/customer/notifications', NotificationInterface::Customer],
            'transitional admin' => ['/api/notifications', NotificationInterface::Admin],
        ];
    }

    public function test_query_interface_cannot_spoof_the_route_contour(): void
    {
        $request = Request::create('/api/v1/landing/notifications?interface=admin', 'GET');

        self::assertSame(
            NotificationInterface::Lk,
            (new NotificationRequestInterfaceResolver)->resolve($request)
        );
    }

    public function test_body_interface_cannot_spoof_the_route_contour(): void
    {
        $request = Request::create('/api/v1/admin/notifications/1/read', 'PATCH', [
            'interface' => 'lk',
        ]);

        self::assertSame(
            NotificationInterface::Admin,
            (new NotificationRequestInterfaceResolver)->resolve($request)
        );
    }

    public function test_unknown_route_fails_closed(): void
    {
        $this->expectException(DomainException::class);

        (new NotificationRequestInterfaceResolver)->resolve(
            Request::create('/api/v1/public/notifications', 'GET', ['interface' => 'admin'])
        );
    }
}
