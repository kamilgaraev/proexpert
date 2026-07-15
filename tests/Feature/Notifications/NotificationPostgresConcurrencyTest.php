<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\BusinessModules\Features\Notifications\Contracts\NotificationPersistence;
use App\BusinessModules\Features\Notifications\DTOs\NotificationDeliveryOptions;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\BusinessModules\Features\Notifications\Services\DatabaseNotificationPersistence;
use App\BusinessModules\Features\Notifications\Services\NotificationInterfaceCursorStore;
use App\BusinessModules\Features\Notifications\Services\NotificationQueryService;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class NotificationPostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_NOTIFICATION_POSTGRES_TESTS') !== '1'
            || DB::getDriverName() !== 'pgsql'
            || ! function_exists('pcntl_fork')
            || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Requires opt-in PostgreSQL concurrency environment with pcntl.');
        }
    }

    public function test_same_recipient_interface_sends_commit_in_advisory_lock_order_and_advance_cursor(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        [$parentSocket, $childSocket] = $this->socketPair();
        $pid = pcntl_fork();

        if ($pid === 0) {
            fclose($parentSocket);
            $this->runBlockedNotificationSend((int) $user->getKey(), $childSocket, 'first');
        }

        fclose($childSocket);

        try {
            self::assertSame('locked', $this->readLine($parentSocket));
            [$secondParent, $secondChild] = $this->socketPair();
            $secondPid = pcntl_fork();

            if ($secondPid === 0) {
                fclose($secondParent);
                $this->runNotificationSend((int) $user->getKey(), $secondChild, 'second');
            }

            fclose($secondChild);
            self::assertSame('started', $this->readLine($secondParent));
            self::assertFalse($this->isReadable($secondParent, 0, 250000));

            fwrite($parentSocket, "release\n");
            self::assertSame('ok', $this->readLine($parentSocket));
            self::assertSame('ok', $this->readLine($secondParent));
            $this->waitForSuccessfulChild($pid);
            $this->waitForSuccessfulChild($secondPid);

            $targets = NotificationTarget::query()
                ->whereHas('notification', static fn ($query) => $query->forUser($user))
                ->where('interface', 'admin')
                ->orderBy('sequence')
                ->get();

            self::assertCount(2, $targets);
            self::assertSame(
                ['first', 'second'],
                $targets->map(static fn (NotificationTarget $target): string => $target->notification->data['label'])->all()
            );
            self::assertSame(
                (int) $targets->last()->sequence,
                (int) DB::table('notification_interface_cursors')
                    ->where('recipient_user_id', $user->getKey())
                    ->where('interface', 'admin')
                    ->value('latest_sequence')
            );
        } finally {
            $this->deleteRecipientNotifications($user);
        }
    }

    public function test_concurrent_mark_as_read_and_mark_all_complete_without_serialization_failure_and_exclude_post_cut_insert(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $first = $this->sendNotification($user, 'first');
        $second = $this->sendNotification($user, 'second');
        $firstTargetId = (string) $first->targets()->where('interface', 'admin')->value('id');
        $expectedCut = (int) $second->targets()->where('interface', 'admin')->value('sequence');
        [$readParent, $readChild] = $this->socketPair();
        $readPid = pcntl_fork();

        if ($readPid === 0) {
            fclose($readParent);
            $this->runHeldMarkAsRead($firstTargetId, $readChild);
        }

        fclose($readChild);

        try {
            self::assertSame('locked', $this->readLine($readParent));
            [$allParent, $allChild] = $this->socketPair();
            $allPid = pcntl_fork();

            if ($allPid === 0) {
                fclose($allParent);
                $this->runMarkAll((int) $user->getKey(), $allChild);
            }

            fclose($allChild);
            self::assertSame('started', $this->readLine($allParent));
            $this->waitForPostgresLock('notification-mark-all-worker');
            $late = $this->sendNotification($user, 'post-cut');

            fwrite($readParent, "release\n");
            self::assertSame('ok', $this->readLine($readParent));
            $markAllResult = json_decode($this->readLine($allParent), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($expectedCut, $markAllResult['sequence_cut']);
            self::assertGreaterThanOrEqual(1, $markAllResult['count']);
            $this->waitForSuccessfulChild($readPid);
            $this->waitForSuccessfulChild($allPid);

            self::assertNull($late->targets()->where('interface', 'admin')->value('read_at'));
        } finally {
            $this->deleteRecipientNotifications($user);
        }
    }

    private function runBlockedNotificationSend(int $userId, mixed $socket, string $label): never
    {
        $this->reconnectAfterFork();
        Queue::fake();
        $cursorStore = app(NotificationInterfaceCursorStore::class);
        app()->forgetInstance(NotificationService::class);
        app()->instance(
            NotificationPersistence::class,
            new BarrierNotificationPersistence(
                new DatabaseNotificationPersistence($cursorStore),
                $socket
            )
        );

        try {
            $this->sendNotification(User::query()->findOrFail($userId), $label);
            fwrite($socket, "ok\n");
            exit(0);
        } catch (Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class.':'.$exception->getMessage()."\n");
            exit(1);
        }
    }

    private function runNotificationSend(int $userId, mixed $socket, string $label): never
    {
        $this->reconnectAfterFork();
        Queue::fake();
        fwrite($socket, "started\n");

        try {
            $this->sendNotification(User::query()->findOrFail($userId), $label);
            fwrite($socket, "ok\n");
            exit(0);
        } catch (Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class.':'.$exception->getMessage()."\n");
            exit(1);
        }
    }

    private function runHeldMarkAsRead(string $targetId, mixed $socket): never
    {
        $this->reconnectAfterFork();

        try {
            DB::transaction(function () use ($targetId, $socket): void {
                NotificationTarget::query()->findOrFail($targetId)->markAsRead();
                fwrite($socket, "locked\n");

                if ($this->readLine($socket) !== 'release') {
                    throw new RuntimeException('Unexpected mark-as-read barrier command.');
                }
            });
            fwrite($socket, "ok\n");
            exit(0);
        } catch (Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class.':'.$exception->getMessage()."\n");
            exit(1);
        }
    }

    private function runMarkAll(int $userId, mixed $socket): never
    {
        $this->reconnectAfterFork();

        try {
            DB::statement("SET application_name = 'notification-mark-all-worker'");
            fwrite($socket, "started\n");
            $user = User::query()->findOrFail($userId);
            $request = Request::create('/api/v1/admin/notifications/mark-all-read', 'POST');
            $request->setUserResolver(static fn (): User => $user);
            $result = app(NotificationQueryService::class)->markAllAsRead($request);
            fwrite($socket, json_encode([
                'count' => $result->count,
                'sequence_cut' => $result->sequenceCut,
            ], JSON_THROW_ON_ERROR)."\n");
            exit(0);
        } catch (Throwable $exception) {
            fwrite($socket, 'error:'.$exception::class.':'.$exception->getMessage()."\n");
            exit(1);
        }
    }

    private function sendNotification(User $user, string $label): Notification
    {
        return app(NotificationService::class)->send(
            $user,
            'system',
            ['label' => $label, 'force_send' => true],
            channels: ['in_app'],
            requiredPermissions: [],
            interfaces: ['admin'],
        );
    }

    private function reconnectAfterFork(): void
    {
        DB::disconnect();
        DB::purge();
        DB::reconnect();
    }

    private function waitForPostgresLock(string $applicationName): void
    {
        $deadline = microtime(true) + 5;

        do {
            $waiting = DB::table('pg_stat_activity')
                ->where('application_name', $applicationName)
                ->where('wait_event_type', 'Lock')
                ->exists();

            if ($waiting) {
                return;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        self::fail('Mark-all worker did not reach the PostgreSQL row-lock barrier.');
    }

    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new RuntimeException('Unable to create notification concurrency barrier.');
        }

        return $pair;
    }

    private function readLine(mixed $socket): string
    {
        if (! $this->isReadable($socket, 5, 0)) {
            throw new RuntimeException('Notification concurrency barrier timed out.');
        }

        $line = fgets($socket);

        if ($line === false) {
            throw new RuntimeException('Notification concurrency worker closed its barrier unexpectedly.');
        }

        return trim($line);
    }

    private function isReadable(mixed $socket, int $seconds, int $microseconds): bool
    {
        $read = [$socket];
        $write = null;
        $except = null;

        return stream_select($read, $write, $except, $seconds, $microseconds) === 1;
    }

    private function waitForSuccessfulChild(int $pid): void
    {
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    private function deleteRecipientNotifications(User $user): void
    {
        Notification::query()->forUser($user)->delete();
        DB::table('notification_interface_cursors')->where('recipient_user_id', $user->getKey())->delete();
        $user->delete();
    }
}

final class BarrierNotificationPersistence implements NotificationPersistence
{
    public function __construct(
        private readonly NotificationPersistence $delegate,
        private readonly mixed $socket,
    ) {}

    public function persist(
        User $user,
        string $type,
        array $data,
        string $notificationType,
        string $priority,
        NotificationDeliveryOptions $options,
    ): Notification {
        fwrite($this->socket, "locked\n");
        $command = fgets($this->socket);

        if ($command === false || trim($command) !== 'release') {
            throw new RuntimeException('Unexpected notification persistence barrier command.');
        }

        return $this->delegate->persist(
            $user,
            $type,
            $data,
            $notificationType,
            $priority,
            $options,
        );
    }
}
