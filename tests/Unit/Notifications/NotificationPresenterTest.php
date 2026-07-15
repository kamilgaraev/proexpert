<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\BusinessModules\Features\Notifications\Services\NotificationPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotificationPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['app.fallback_locale' => 'ru']));
        $container->instance('translator', new class
        {
            public function get(string $key, array $replace = [], ?string $locale = null): string
            {
                return $key;
            }
        });
        $container->instance('log', new NullLogger);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_exposes_only_current_target_state_without_legacy_or_internal_target_fields(): void
    {
        $legacyReadAt = CarbonImmutable::parse('2026-07-01 10:00:00');
        $targetReadAt = CarbonImmutable::parse('2026-07-15 11:00:00');
        $notification = new class extends Notification
        {
            protected $dateFormat = 'Y-m-d H:i:s';
        };
        $notification->forceFill([
            'type' => 'system.notice',
            'read_at' => $legacyReadAt,
            'data' => ['title' => 'МОСТ'],
        ]);
        $notification->forceFill(['id' => 'notification-id']);
        $target = new class extends NotificationTarget
        {
            protected $dateFormat = 'Y-m-d H:i:s';
        };
        $target->forceFill([
            'interface' => NotificationInterface::Lk,
            'read_at' => $targetReadAt,
            'dismissed_at' => null,
            'websocket_status' => 'failed',
            'websocket_last_error' => 'internal',
            'sequence' => 501,
        ]);
        $notification->setRelation('targets', new Collection([$target]));

        $payload = (new NotificationPresenter)->present($notification);

        self::assertSame('notification-id', $payload['id']);
        self::assertSame($targetReadAt->toJSON(), $payload['read_at']);
        self::assertNull($payload['dismissed_at']);
        self::assertSame(501, $payload['sequence']);
        self::assertArrayNotHasKey('targets', $payload);
        self::assertStringNotContainsString('websocket', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertNotSame($legacyReadAt->toJSON(), $payload['read_at']);
    }

    public function test_customer_presentation_preserves_portal_fields_using_current_target_state(): void
    {
        $notification = new class extends Notification
        {
            protected $dateFormat = 'Y-m-d H:i:s';
        };
        $notification->forceFill([
            'id' => 'customer-notification-id',
            'notification_type' => 'contract.updated',
            'priority' => 'high',
            'data' => [
                'title' => 'Договор обновлён',
                'message' => 'Проверьте изменения',
                'project' => ['id' => 15],
            ],
            'read_at' => CarbonImmutable::parse('2026-07-01 10:00:00'),
        ]);
        $target = new class extends NotificationTarget
        {
            protected $dateFormat = 'Y-m-d H:i:s';
        };
        $target->forceFill([
            'interface' => NotificationInterface::Customer,
            'read_at' => null,
            'dismissed_at' => null,
            'sequence' => 601,
        ]);
        $notification->setRelation('targets', new Collection([$target]));

        $payload = (new NotificationPresenter)->presentForCustomer($notification);

        self::assertSame('customer-notification-id', $payload['id']);
        self::assertSame('Договор обновлён', $payload['title']);
        self::assertSame('Проверьте изменения', $payload['description']);
        self::assertSame('contract.updated', $payload['eventType']);
        self::assertTrue($payload['isUnread']);
        self::assertSame(601, $payload['sequence']);
        self::assertSame('warning', $payload['tone']);
        self::assertSame(['id' => 15], $payload['project']);
        self::assertArrayHasKey('statusLabel', $payload);
        self::assertArrayNotHasKey('targets', $payload);
        self::assertArrayNotHasKey('read_at', $payload);
    }
}
