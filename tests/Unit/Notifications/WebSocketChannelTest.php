<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

require_once dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Channels/WebSocketChannel.php';

use App\BusinessModules\Features\Notifications\Channels\WebSocketAnalyticsRecorder;
use App\BusinessModules\Features\Notifications\Channels\WebSocketChannel;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Tests\Unit\Notifications\Support\InMemoryNotificationTarget;

final class WebSocketChannelTest extends TestCase
{
    private RecordingWebSocketLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingWebSocketLogger;
        $container = new Container;
        $container->instance('config', new Repository(['broadcasting.default' => 'reverb']));
        $container->instance('log', $this->logger);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_it_publishes_each_supported_target_with_independent_payload_and_read_state(): void
    {
        $broadcasts = [];
        $factory = $this->broadcastFactory($broadcasts);
        $notification = $this->notification(
            ['admin', 'lk'],
            readAt: [CarbonImmutable::parse('2026-07-15 10:00:00'), null],
        );

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
        self::assertSame('2026-07-15T10:00:00+00:00', $broadcasts[0]['payload']['read_at']);
        self::assertNull($broadcasts[1]['payload']['read_at']);
        self::assertArrayNotHasKey('interface', $notification->data);
        self::assertSame(
            ['sent', 'sent'],
            $notification->targets->map(static fn (InMemoryNotificationTarget $target): string => $target->fresh()->websocket_status)->all(),
        );
    }

    public function test_broadcast_failure_is_preserved_when_failed_status_cannot_be_saved_and_later_targets_continue(): void
    {
        $broadcastChannels = [];
        $factory = $this->broadcastFactory(
            $broadcastChannels,
            static function (array $channels): void {
                if (str_ends_with($channels[0], '.admin')) {
                    throw new RuntimeException('Admin broadcast unavailable');
                }
            },
            channelsOnly: true,
        );
        $notification = $this->notification(['admin', 'lk']);
        $notification->targets[0]->failNextSave(new RuntimeException('Target state unavailable'));

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
        self::assertSame('pending', $notification->targets[0]->fresh()->websocket_status);
        self::assertSame('sent', $notification->targets[1]->fresh()->websocket_status);
        self::assertTrue($this->logger->containsWarning('WebSocket target state persistence failed'));
    }

    public function test_sent_status_failure_marks_target_failed_best_effort_and_later_targets_continue(): void
    {
        $broadcastChannels = [];
        $factory = $this->broadcastFactory($broadcastChannels, channelsOnly: true);
        $notification = $this->notification(['admin', 'lk']);
        $notification->targets[0]->failNextSave(new RuntimeException('Sent state unavailable'));

        try {
            $this->channel($factory)->publish($notification, (object) ['id' => 42]);
            self::fail('Target state failure was not propagated');
        } catch (RuntimeException $exception) {
            self::assertSame('Sent state unavailable', $exception->getMessage());
        }

        self::assertSame([
            'private-App.Models.User.42.admin',
            'private-App.Models.User.42.lk',
        ], $broadcastChannels);
        self::assertSame('failed', $notification->targets[0]->fresh()->websocket_status);
        self::assertSame('sent', $notification->targets[1]->fresh()->websocket_status);
        self::assertTrue($this->logger->containsWarning('WebSocket target state persistence failed'));
    }

    public function test_retry_skips_durably_sent_targets_and_retries_failed_targets_only(): void
    {
        $broadcastChannels = [];
        $factory = $this->broadcastFactory($broadcastChannels, channelsOnly: true);
        $notification = $this->notification(['admin', 'lk'], ['sent', 'failed']);

        $this->channel($factory)->publish($notification, (object) ['id' => 42]);

        self::assertSame(['private-App.Models.User.42.lk'], $broadcastChannels);
        self::assertSame(
            ['sent', 'sent'],
            $notification->targets->map(static fn (InMemoryNotificationTarget $target): string => $target->fresh()->websocket_status)->all(),
        );
    }

    public function test_analytics_create_failure_does_not_block_successful_delivery(): void
    {
        $broadcasts = [];
        $analytics = Mockery::mock(WebSocketAnalyticsRecorder::class);
        $analytics->shouldReceive('start')->once()->andThrow(new RuntimeException('Analytics unavailable'));
        $analytics->shouldNotReceive('markSent');
        $analytics->shouldNotReceive('markFailed');

        $result = (new WebSocketChannel($this->broadcastFactory($broadcasts), $analytics))
            ->send((object) ['id' => 42], $this->notification(['admin']));

        self::assertTrue($result);
        self::assertCount(1, $broadcasts);
        self::assertTrue($this->logger->containsWarning('WebSocket analytics persistence failed'));
    }

    public function test_analytics_sent_update_failure_does_not_turn_successful_delivery_into_retry(): void
    {
        $broadcasts = [];
        $analyticsModel = new NotificationAnalytics;
        $analytics = Mockery::mock(WebSocketAnalyticsRecorder::class);
        $analytics->shouldReceive('start')->once()->andReturn($analyticsModel);
        $analytics->shouldReceive('markSent')->once()->with($analyticsModel)->andThrow(new RuntimeException('Analytics update unavailable'));
        $analytics->shouldNotReceive('markFailed');
        $notification = $this->notification(['admin']);

        $result = (new WebSocketChannel($this->broadcastFactory($broadcasts), $analytics))
            ->send((object) ['id' => 42], $notification);

        self::assertTrue($result);
        self::assertCount(1, $broadcasts);
        self::assertSame('sent', $notification->targets[0]->fresh()->websocket_status);
    }

    public function test_analytics_failed_update_cannot_mask_the_delivery_exception(): void
    {
        $broadcasts = [];
        $analyticsModel = new NotificationAnalytics;
        $analytics = Mockery::mock(WebSocketAnalyticsRecorder::class);
        $analytics->shouldReceive('start')->once()->andReturn($analyticsModel);
        $analytics->shouldReceive('markFailed')->once()->with($analyticsModel, Mockery::type(RuntimeException::class))
            ->andThrow(new RuntimeException('Analytics update unavailable'));
        $analytics->shouldNotReceive('markSent');
        $factory = $this->broadcastFactory(
            $broadcasts,
            static fn () => throw new RuntimeException('Reverb unavailable'),
        );

        try {
            (new WebSocketChannel($factory, $analytics))->send((object) ['id' => 42], $this->notification(['admin']));
            self::fail('Delivery exception was not propagated');
        } catch (RuntimeException $exception) {
            self::assertSame('Reverb unavailable', $exception->getMessage());
        }

        self::assertTrue($this->logger->containsWarning('WebSocket analytics persistence failed'));
    }

    public function test_partial_attempt_is_recorded_as_failed_in_analytics(): void
    {
        $broadcasts = [];
        $analyticsModel = new NotificationAnalytics;
        $analytics = Mockery::mock(WebSocketAnalyticsRecorder::class);
        $analytics->shouldReceive('start')->once()->andReturn($analyticsModel);
        $analytics->shouldReceive('markFailed')->once()->with($analyticsModel, Mockery::type(RuntimeException::class));
        $analytics->shouldNotReceive('markSent');
        $factory = $this->broadcastFactory(
            $broadcasts,
            static function (array $channels): void {
                if (str_ends_with($channels[0], '.admin')) {
                    throw new RuntimeException('Admin broadcast unavailable');
                }
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin broadcast unavailable');

        (new WebSocketChannel($factory, $analytics))->send(
            (object) ['id' => 42],
            $this->notification(['admin', 'lk']),
        );
    }

    public function test_it_rejects_an_unsupported_persisted_target_before_publishing_any_target(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported notification interface');

        $this->channel($factory)->publish($this->notification(['admin', 'mobile']), (object) ['id' => 42]);
    }

    public function test_it_rejects_a_notification_without_targets(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires at least one target');

        $this->channel($factory)->publish($this->notification([]), (object) ['id' => 42]);
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

    private function broadcastFactory(
        array &$broadcasts,
        ?callable $effect = null,
        bool $channelsOnly = false,
    ): Factory {
        $broadcaster = Mockery::mock(Broadcaster::class);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->andReturn($broadcaster);
        $broadcaster->shouldReceive('broadcast')
            ->andReturnUsing(static function (array $channels, string $event, array $payload) use (
                &$broadcasts,
                $effect,
                $channelsOnly,
            ): void {
                $broadcasts[] = $channelsOnly ? $channels[0] : compact('channels', 'event', 'payload');
                $effect?->__invoke($channels, $event, $payload);
            });

        return $factory;
    }

    private function notification(
        array $interfaces = ['lk'],
        array $statuses = [],
        array $readAt = [],
    ): Notification {
        $notification = new Notification;
        $notification->setRawAttributes([
            'id' => 'notification-id',
            'type' => 'system',
            'notification_type' => 'system',
            'priority' => 'normal',
            'data' => json_encode(['title' => 'Test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'read_at' => now()->subDay(),
        ], true);
        $notification->setRelation('targets', collect(array_map(
            static fn (string $interface, int $index): InMemoryNotificationTarget => new InMemoryNotificationTarget([
                'interface' => NotificationInterface::from($interface),
                'websocket_status' => $statuses[$index] ?? 'pending',
                'read_at' => $readAt[$index] ?? null,
            ]),
            $interfaces,
            array_keys($interfaces),
        )));

        return $notification;
    }
}

final class RecordingWebSocketLogger extends AbstractLogger
{
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = compact('level', 'message', 'context');
    }

    public function containsWarning(string $message): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === 'warning' && $record['message'] === $message) {
                return true;
            }
        }

        return false;
    }
}
