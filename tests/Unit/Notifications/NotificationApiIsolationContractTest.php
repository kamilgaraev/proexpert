<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;

final class NotificationApiIsolationContractTest extends TestCase
{
    public function test_query_service_enforces_user_contour_dismissal_and_organization_scope(): void
    {
        $source = $this->source('app/BusinessModules/Features/Notifications/Services/NotificationQueryService.php');

        self::assertStringContainsString('->forUser($user)', $source);
        self::assertStringContainsString("whereNull('organization_id')", $source);
        self::assertStringContainsString("orWhere('organization_id', \$organizationId)", $source);
        self::assertStringContainsString("where('interface', \$interface->value)", $source);
        self::assertStringContainsString("whereNull('dismissed_at')", $source);
        self::assertStringContainsString("'targets' =>", $source);
    }

    public function test_controller_uses_target_state_and_all_trusted_response_wrappers(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Notifications/Http/Controllers/NotificationController.php'
        );

        self::assertStringNotContainsString("with('analytics')", $source);
        self::assertStringNotContainsString('->delete()', $source);
        self::assertStringNotContainsString("update(['read_at' => now()])", $source);
        self::assertStringContainsString('NotificationRequestInterfaceResolver', $source);
        self::assertStringContainsString('NotificationQueryService', $source);
        self::assertStringContainsString('NotificationPresenter', $source);
        self::assertStringContainsString('AdminResponse::', $source);
        self::assertStringContainsString('LandingResponse::', $source);
        self::assertStringContainsString('MobileResponse::', $source);
        self::assertStringContainsString('CustomerResponse::', $source);
        self::assertStringContainsString('max(1, min(100,', $source);
        self::assertStringContainsString('presentForCustomer', $source);
        self::assertStringContainsString("'organization_id' =>", $source);
        self::assertStringContainsString("'unread_count' =>", $source);
        self::assertStringContainsString("trans_message('customer.notifications_loaded')", $source);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);

        self::assertIsString($source);

        return $source;
    }
}
