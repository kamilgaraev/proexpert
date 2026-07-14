<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

require_once dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Channels/WebSocketChannel.php';

use App\BusinessModules\Features\Notifications\Channels\WebSocketChannel;
use App\BusinessModules\Features\Notifications\Models\Notification;
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

    public function test_it_publishes_the_user_notification_through_the_reverb_broadcaster(): void
    {
        $broadcaster = Mockery::mock(Broadcaster::class);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);
        $broadcaster->shouldReceive('broadcast')
            ->once()
            ->with(
                ['private-App.Models.User.42.lk'],
                'notification.new',
                Mockery::on(static fn (array $payload): bool => $payload['id'] === 'notification-id'
                    && $payload['data']['interface'] === 'lk'
                    && $payload['read_at'] === null
                )
            );

        $this->channel($factory)->publish($this->notification(), (object) ['id' => 42]);
        self::assertTrue(true);
    }

    public function test_it_does_not_hide_a_reverb_delivery_error(): void
    {
        $broadcaster = Mockery::mock(Broadcaster::class);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);
        $broadcaster->shouldReceive('broadcast')->once()->andThrow(new RuntimeException('Reverb unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reverb unavailable');

        $this->channel($factory)->publish($this->notification(), (object) ['id' => 42]);
    }

    public function test_it_rejects_an_unknown_interface_before_publishing(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');
        $notification = $this->notification();
        $notification->data = ['interface' => 'mobile'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported notification interface');

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

    private function notification(): Notification
    {
        $notification = new Notification;
        $notification->setRawAttributes([
            'id' => 'notification-id',
            'type' => 'system',
            'notification_type' => 'system',
            'priority' => 'normal',
            'data' => json_encode(['interface' => 'lk', 'title' => 'Test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'read_at' => null,
        ], true);

        return $notification;
    }
}
