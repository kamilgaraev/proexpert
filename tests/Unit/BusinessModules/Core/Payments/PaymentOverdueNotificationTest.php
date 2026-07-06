<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Notifications\PaymentOverdueNotification;
use PHPUnit\Framework\TestCase;

final class PaymentOverdueNotificationTest extends TestCase
{
    public function test_overdue_notification_uses_database_channel_only(): void
    {
        $notification = new PaymentOverdueNotification(new PaymentDocument(), 10);

        self::assertSame(['database'], $notification->via(null));
    }
}
