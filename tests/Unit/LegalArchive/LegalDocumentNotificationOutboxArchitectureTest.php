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
        self::assertStringContainsString('DatabaseNotification::query()->firstOrCreate', $source);
        self::assertStringContainsString('[\'id\' => $locked->notification_id]', $source);
        self::assertStringNotContainsString('->notify(', $source);
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
