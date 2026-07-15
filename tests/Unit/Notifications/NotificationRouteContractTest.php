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

    public function test_both_customer_aliases_retain_authentication_and_organization_context(): void
    {
        $apiRoutes = $this->source('routes/api.php');
        $customerRoutes = $this->source('routes/api/v1/customer.php');

        self::assertStringContainsString("Route::prefix('v1/customer')", $apiRoutes);
        self::assertStringContainsString("Route::prefix('customer')", $apiRoutes);
        self::assertSame(2, substr_count($apiRoutes, "require __DIR__ . '/api/v1/customer.php';"));
        self::assertStringContainsString(
            "['auth:api_landing', 'auth.jwt:api_landing', 'verified', 'organization.context']",
            $customerRoutes
        );
        self::assertStringContainsString("NotificationController::class, 'index'", $customerRoutes);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);

        self::assertIsString($source);

        return $source;
    }
}
