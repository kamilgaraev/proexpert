<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;

final class NotificationRouteContractTest extends TestCase
{
    public function test_landing_and_customer_routes_expose_all_target_scoped_operations(): void
    {
        foreach ([
            'routes/api/v1/landing/notifications.php',
            'routes/api/v1/customer.php',
        ] as $path) {
            $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);

            self::assertIsString($source);
            self::assertStringContainsString("NotificationController::class, 'index'", $source);
            self::assertStringContainsString("NotificationController::class, 'getUnreadCount'", $source);
            self::assertStringContainsString("NotificationController::class, 'show'", $source);
            self::assertStringContainsString("NotificationController::class, 'markAsRead'", $source);
            self::assertStringContainsString("NotificationController::class, 'markAsUnread'", $source);
            self::assertStringContainsString("NotificationController::class, 'markAllAsRead'", $source);
            self::assertStringContainsString("NotificationController::class, 'destroy'", $source);
        }
    }
}
