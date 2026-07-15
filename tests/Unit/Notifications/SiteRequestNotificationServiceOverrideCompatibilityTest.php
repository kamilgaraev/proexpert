<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Tests\Feature\SiteRequests\SiteRequestDraftPublicationTest;

final class SiteRequestNotificationServiceOverrideCompatibilityTest extends TestCase
{
    public function test_site_request_notification_service_override_compiles_with_current_send_contract(): void
    {
        $target = dirname(__DIR__, 3).'/tests/Feature/SiteRequests/SiteRequestDraftPublicationTest.php';

        self::assertFileExists($target);

        require_once $target;

        self::assertTrue(class_exists(SiteRequestDraftPublicationTest::class, false));
    }
}
