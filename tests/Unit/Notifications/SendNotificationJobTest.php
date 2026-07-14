<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

require_once dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Jobs/SendNotificationJob.php';

use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SendNotificationJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance('config', new Repository([
            'notifications.priorities.normal' => ['retry_times' => 3, 'retry_after' => 300],
        ]));
        $container->instance('log', Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing());
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

    public function test_retry_skips_sent_channels_and_rethrows_after_attempting_remaining_channels(): void
    {
        $notification = new Notification;
        $notification->setRawAttributes([
            'id' => 'notification-id',
            'priority' => 'normal',
            'channels' => json_encode(['email', 'websocket', 'telegram'], JSON_THROW_ON_ERROR),
            'delivery_status' => json_encode(['email' => 'sent'], JSON_THROW_ON_ERROR),
        ], true);

        $service = Mockery::mock(NotificationService::class);
        $service->shouldNotReceive('sendViaChannel')->with($notification, 'email');
        $service->shouldReceive('sendViaChannel')
            ->once()
            ->with($notification, 'websocket')
            ->andThrow(new RuntimeException('Reverb unavailable'));
        $service->shouldReceive('sendViaChannel')
            ->once()
            ->with($notification, 'telegram')
            ->andReturnTrue();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reverb unavailable');

        (new SendNotificationJob($notification))->handle($service);
    }
}
