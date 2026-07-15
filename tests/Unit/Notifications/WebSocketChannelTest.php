<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

require_once dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Channels/WebSocketChannel.php';

use App\BusinessModules\Features\Notifications\Channels\WebSocketChannel;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebSocketChannelTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_publishes_each_supported_target_with_an_independent_payload(): void
    {
        $broadcasts = [];
        $broadcaster = Mockery::mock(Broadcaster::class);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->twice()->with('reverb')->andReturn($broadcaster);
        $broadcaster->shouldReceive('broadcast')
            ->twice()
            ->andReturnUsing(static function (array $channels, string $event, array $payload) use (&$broadcasts): void {
                $broadcasts[] = compact('channels', 'event', 'payload');
            });

        $notification = $this->notification(['admin', 'lk']);

        $this->channel($factory)->publish($notification, (object) ['id' => 42]);

        self::assertSame([
            'private-App.Models.User.42.admin',
            'private-App.Models.User.42.lk',
        ], array_map(static fn (array $broadcast): string => $broadcast['channels'][0], $broadcasts));
        self::assertSame(['notification.new', 'notification.new'], array_column($broadcasts, 'event'));
        self::assertSame(['admin', 'lk'], array_column(array_column($broadcasts, 'payload'), 'interface'));
        self::assertSame(['admin', 'lk'], array_map(
            static fn (array $broadcast): string => $broadcast['payload']['data']['interface'],
            $broadcasts,
        ));
        self::assertArrayNotHasKey('interface', $notification->data);
        self::assertSame(['sent', 'sent'], $notification->targets->pluck('websocket_status')->all());
    }

    public function test_partial_failure_continues_other_targets_and_rethrows(): void
    {
        $broadcastChannels = [];
        $broadcaster = Mockery::mock(Broadcaster::class);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->twice()->with('reverb')->andReturn($broadcaster);
        $broadcaster->shouldReceive('broadcast')
            ->twice()
            ->andReturnUsing(static function (array $channels) use (&$broadcastChannels): void {
                $broadcastChannels[] = $channels[0];

                if (str_ends_with($channels[0], '.admin')) {
                    throw new RuntimeException('Admin broadcast unavailable');
                }
            });

        $notification = $this->notification(['admin', 'lk']);

        try {
            $this->channel($factory)->publish($notification, (object) ['id' => 42]);
            self::fail('Partial delivery failure was not propagated');
        } catch (RuntimeException $exception) {
            self::assertSame('Admin broadcast unavailable', $exception->getMessage());
        }

        self::assertSame([
            'private-App.Models.User.42.admin',
            'private-App.Models.User.42.lk',
        ], $broadcastChannels);
        self::assertSame(['failed', 'sent'], $notification->targets->pluck('websocket_status')->all());
        self::assertSame('Admin broadcast unavailable', $notification->targets[0]->websocket_last_error);
    }

    public function test_retry_skips_sent_targets_and_retries_failed_targets_only(): void
    {
        $broadcastChannels = [];
        $broadcaster = Mockery::mock(Broadcaster::class);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);
        $broadcaster->shouldReceive('broadcast')
            ->once()
            ->andReturnUsing(static function (array $channels) use (&$broadcastChannels): void {
                $broadcastChannels[] = $channels[0];
            });
        $notification = $this->notification(['admin', 'lk'], ['sent', 'failed']);

        $this->channel($factory)->publish($notification, (object) ['id' => 42]);

        self::assertSame(['private-App.Models.User.42.lk'], $broadcastChannels);
        self::assertSame(['sent', 'sent'], $notification->targets->pluck('websocket_status')->all());
    }

    public function test_it_rejects_an_unsupported_persisted_target_before_publishing_any_target(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');
        $notification = $this->notification(['admin', 'mobile']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported notification interface');

        $this->channel($factory)->publish($notification, (object) ['id' => 42]);
    }

    public function test_it_rejects_a_notification_without_targets(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');
        $notification = $this->notification([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires at least one target');

        $this->channel($factory)->publish($notification, (object) ['id' => 42]);
    }

    private function channel(Factory $factory): WebSocketChannel
    {
        return new class($factory) extends WebSocketChannel
        {
            public function publish(Notification $notification, object $notifiable): void
            {
                $this->broadcastNotification($notification, $notifiable);
            }
        };
    }

    private function notification(array $interfaces = ['lk'], array $statuses = []): Notification
    {
        $notification = new Notification;
        $notification->setRawAttributes([
            'id' => 'notification-id',
            'type' => 'system',
            'notification_type' => 'system',
            'priority' => 'normal',
            'data' => json_encode(['title' => 'Test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'read_at' => null,
        ], true);
        $notification->setRelation('targets', collect(array_map(
            static function (string $interface, int $index) use ($statuses): NotificationTarget {
                $target = new class extends NotificationTarget
                {
                    protected $dateFormat = 'Y-m-d H:i:s';

                    public function save(array $options = []): bool
                    {
                        return true;
                    }
                };
                $target->forceFill([
                    'interface' => NotificationInterface::from($interface),
                    'websocket_status' => $statuses[$index] ?? 'pending',
                ]);

                return $target;
            }, $interfaces, array_keys($interfaces),
        )));

        return $notification;
    }
}
