<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

require_once dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Jobs/SendNotificationJob.php';

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
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

    public function test_retry_policy_is_bounded_and_uses_laravel_backoff(): void
    {
        $notification = new Notification;
        $notification->setRawAttributes([
            'priority' => 'normal',
            'channels' => json_encode(['websocket'], JSON_THROW_ON_ERROR),
            'delivery_status' => json_encode([], JSON_THROW_ON_ERROR),
        ], true);

        $job = new SendNotificationJob($notification);

        self::assertSame(3, $job->tries);
        self::assertSame(300, $job->backoff);
        self::assertFalse(method_exists($job, 'retryUntil'));
    }

    public function test_websocket_global_sent_status_does_not_mask_a_failed_target(): void
    {
        $notification = new Notification;
        $notification->setRawAttributes([
            'id' => 'notification-id',
            'priority' => 'normal',
            'channels' => json_encode(['websocket', 'email'], JSON_THROW_ON_ERROR),
            'delivery_status' => json_encode(['websocket' => 'sent', 'email' => 'sent'], JSON_THROW_ON_ERROR),
        ], true);
        $failedTarget = new NotificationTarget;
        $failedTarget->forceFill([
            'interface' => NotificationInterface::Lk,
            'websocket_status' => 'failed',
        ]);
        $notification->setRelation('targets', collect([$failedTarget]));

        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('sendViaChannel')
            ->once()
            ->with($notification, 'websocket')
            ->andReturnTrue();
        $service->shouldNotReceive('sendViaChannel')->with($notification, 'email');

        (new SendNotificationJob($notification))->handle($service);

        self::assertSame('failed', $failedTarget->websocket_status);
    }
}
