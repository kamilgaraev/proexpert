<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;

final class NotificationAfterCommitDispatchTest extends TestCase
{
    public function test_notification_jobs_are_dispatched_after_commit(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Services/NotificationService.php'
        );

        self::assertIsString($source);
        $source = str_replace("\r\n", "\n", $source);

        self::assertStringContainsString(
            "SendNotificationJob::dispatch(\$notification)\n"
            ."            ->afterCommit()\n"
            .'            ->onQueue($queue);',
            $source
        );
    }
}
