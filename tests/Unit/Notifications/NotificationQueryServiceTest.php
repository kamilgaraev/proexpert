<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\LaravelNotificationSnapshotDatabase;
use App\BusinessModules\Features\Notifications\Services\NotificationQueryService;
use App\BusinessModules\Features\Notifications\Services\NotificationRequestInterfaceResolver;
use App\BusinessModules\Features\Notifications\Services\NotificationSnapshotTransactionRunner;
use App\Models\User;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\TestCase;

final class NotificationQueryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ConnectionResolver([
            'testing' => new SQLiteConnection(null, ':memory:'),
        ]);
        $resolver->setDefaultConnection('testing');
        Notification::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        Notification::unsetConnectionResolver();

        parent::tearDown();
    }

    public function test_customer_unread_scope_uses_target_state_user_and_current_or_global_organization(): void
    {
        $query = $this->service()->unreadFor(
            $this->user(),
            NotificationInterface::Customer,
            42
        );
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        self::assertStringContainsString('"notifiable_type" = ?', $sql);
        self::assertStringContainsString('"notifiable_id" = ?', $sql);
        self::assertStringContainsString('"organization_id" is null or "organization_id" = ?', $sql);
        self::assertStringContainsString('"interface" = ?', $sql);
        self::assertStringContainsString('"dismissed_at" is null', $sql);
        self::assertStringContainsString('"read_at" is null', $sql);
        self::assertContains(User::class, $bindings);
        self::assertContains(777, $bindings);
        self::assertContains(42, $bindings);
        self::assertContains(NotificationInterface::Customer->value, $bindings);
    }

    public function test_missing_current_organization_fails_closed_to_global_notifications(): void
    {
        $query = $this->service()->visibleFor(
            $this->user(),
            NotificationInterface::Customer,
            null
        );

        self::assertStringContainsString('"organization_id" is null', $query->toSql());
        self::assertStringNotContainsString('or "organization_id" = ?', $query->toSql());
        self::assertNotContains(42, $query->getBindings());
    }

    private function service(): NotificationQueryService
    {
        return new NotificationQueryService(
            new NotificationRequestInterfaceResolver,
            new NotificationSnapshotTransactionRunner(new LaravelNotificationSnapshotDatabase)
        );
    }

    private function user(): User
    {
        $user = new User;
        $user->forceFill(['id' => 777]);

        return $user;
    }
}
