<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentNotificationOutboxArchitectureTest extends TestCase
{
    public function test_delivery_and_database_notification_share_one_transactional_idempotency_key(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Services/LegalArchive/LegalDocumentNotificationPublisher.php',
        );

        self::assertStringContainsString('\'notification_id\' => $delivery?->notification_id ?? (string) Str::uuid()', $source);
        self::assertStringContainsString('DatabaseNotification::query()->find($locked->notification_id)', $source);
        self::assertStringContainsString("'id' => \$locked->notification_id", $source);
        self::assertStringNotContainsString('->notify(', $source);
    }

    public function test_notification_model_retains_a_delivery_supplied_uuid_before_persistence(): void
    {
        $notification = new \App\BusinessModules\Features\Notifications\Models\Notification();
        $notification->forceFill(['id' => '155f1318-15db-4b8d-a4fe-5f9f9754da97']);

        self::assertSame('155f1318-15db-4b8d-a4fe-5f9f9754da97', $notification->getAttribute('id'));
    }

    public function test_recovery_claims_the_same_notification_id_before_persisting_it(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Services/LegalArchive/LegalDocumentNotificationRecoveryService.php',
        );

        self::assertStringContainsString('$notificationId = $delivery->notification_id ?? (string) Str::uuid();', $source);
        self::assertStringContainsString('\'notification_id\' => $notificationId', $source);
        self::assertStringContainsString('persistClaimed($delivery, $delivery->recipient)', $source);
    }
}
