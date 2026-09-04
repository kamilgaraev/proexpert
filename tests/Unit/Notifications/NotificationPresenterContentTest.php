<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\BusinessModules\Features\Notifications\Services\NotificationPresenter;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class NotificationPresenterContentTest extends TestCase
{
    use UsesNotificationTranslations;

    public function test_historical_event_is_readable_without_writing_or_exposing_other_targets(): void
    {
        $target = $this->getMockBuilder(NotificationTarget::class)
            ->onlyMethods(['getAttribute', 'save'])->getMock();
        $target->method('getAttribute')->willReturnMap([
            ['read_at', null],
            ['dismissed_at', null],
            ['sequence', 12],
            ['interface', NotificationInterface::from('lk')],
        ]);
        $target->expects(self::never())->method('save');

        $notification = $this->getMockBuilder(Notification::class)
            ->onlyMethods(['getAttribute', 'getRelation', 'toArray', 'save'])->getMock();
        $notification->method('getAttribute')->with('type')->willReturn('contract_status_changed');
        $notification->method('getRelation')->with('targets')->willReturn(new Collection([$target]));
        $notification->method('toArray')->willReturn([
            'id' => 'notification-42',
            'data' => [
                'title' => 'contract_status_changed',
                'contract' => ['number' => '42'],
                'new_status' => 'active',
            ],
            'targets' => [['private' => true]],
            'analytics' => ['private' => true],
        ]);
        $notification->expects(self::never())->method('save');

        $payload = (new NotificationPresenter)->present($notification);

        self::assertSame('Изменён статус договора № 42', $payload['data']['title']);
        self::assertSame('Новый статус: «Активен».', $payload['data']['message']);
        self::assertSame('lk', $payload['interface']);
        self::assertSame(12, $payload['sequence']);
        self::assertNull($payload['read_at']);
        self::assertArrayNotHasKey('targets', $payload);
        self::assertArrayNotHasKey('analytics', $payload);
    }
}
