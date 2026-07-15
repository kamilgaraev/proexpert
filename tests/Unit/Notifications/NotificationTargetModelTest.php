<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationTargetModelTest extends TestCase
{
    #[Test]
    public function notification_interfaces_have_the_expected_persisted_values(): void
    {
        self::assertSame(
            ['admin', 'lk', 'mobile', 'customer'],
            array_column(NotificationInterface::cases(), 'value')
        );
    }

    #[Test]
    public function notification_exposes_targets_and_can_be_scoped_by_interface(): void
    {
        $model = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Models/Notification.php'
        );

        self::assertStringContainsString('public function targets(): HasMany', $model);
        self::assertStringContainsString('return $this->hasMany(NotificationTarget::class)', $model);
        self::assertStringContainsString(
            'public function scopeForInterface(Builder $query, NotificationInterface $interface): Builder',
            $model
        );
        self::assertStringContainsString("where('interface', \$interface->value)", $model);
    }

    #[Test]
    public function target_state_transitions_are_independent_and_persisted(): void
    {
        $target = new class extends NotificationTarget
        {
            public int $saveCount = 0;

            protected $dateFormat = 'Y-m-d H:i:s';

            public function save(array $options = []): bool
            {
                $this->saveCount++;

                return true;
            }
        };

        $target->markAsRead();
        self::assertNotNull($target->read_at);

        $target->dismiss();
        self::assertNotNull($target->dismissed_at);

        $target->markAsUnread();
        self::assertNull($target->read_at);
        self::assertNotNull($target->dismissed_at);

        $target->markWebSocketFailed('Reverb unavailable');
        self::assertSame('failed', $target->websocket_status);
        self::assertSame('Reverb unavailable', $target->websocket_last_error);

        $target->markWebSocketSent();
        self::assertSame('sent', $target->websocket_status);
        self::assertNotNull($target->websocket_delivered_at);
        self::assertNull($target->websocket_last_error);
        self::assertSame(5, $target->saveCount);
    }
}
