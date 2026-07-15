<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\BusinessModules\Features\Notifications\Contracts\NotificationMarkAllReadGateway;
use App\BusinessModules\Features\Notifications\Contracts\NotificationPersistence;
use App\BusinessModules\Features\Notifications\DTOs\NotificationDeliveryOptions;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\BusinessModules\Features\Notifications\Services\DatabaseNotificationMarkAllReadGateway;
use App\BusinessModules\Features\Notifications\Services\DatabaseNotificationPersistence;
use App\BusinessModules\Features\Notifications\Services\NotificationInterfaceCursorStore;
use App\BusinessModules\Features\Notifications\Services\NotificationQueryService;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

        if (getenv('RUN_NOTIFICATION_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped('PostgreSQL concurrency tests are not enabled.');
        }

        $missingRequirements = array_keys(array_filter([
            'pcntl_fork' => ! function_exists('pcntl_fork'),
            'stream_socket_pair' => ! function_exists('stream_socket_pair'),
            'posix_kill' => ! function_exists('posix_kill'),
        ]));

        if ($missingRequirements !== []) {
            self::fail('PostgreSQL concurrency environment is incomplete: '.implode(', ', $missingRequirements));
        }

        if (DB::getDriverName() !== 'pgsql') {
            self::fail('PostgreSQL concurrency tests require the pgsql database driver.');
        }
    }

    public function test_same_recipient_interface_sends_commit_in_advisory_lock_order_and_advance_cursor(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $children = [];
        $barriers = [];
        [$firstParent, $firstChild] = $this->socketPair();
        $firstPid = $this->fork();

        if ($firstPid === 0) {
            fclose($firstParent);
            $this->runBlockedNotificationSend((int) $user->getKey(), $firstChild, 'first');
        }

        fclose($firstChild);
        $children[] = $firstPid;
        $barriers[] = $firstParent;

        try {
            self::assertSame('locked', $this->readLine($firstParent));
            [$secondParent, $secondChild] = $this->socketPair();
            $secondPid = $this->fork();

            if ($secondPid === 0) {
                fclose($secondParent);
                $this->runNotificationSend(
                    (int) $user->getKey(),
                    $secondChild,
                    'second',
                    'notification-send-worker'
                );
            }

            fclose($secondChild);
            $children[] = $secondPid;
            $barriers[] = $secondParent;
            self::assertSame('started', $this->readLine($secondParent));
            $this->waitForPostgresLock('notification-send-worker', 'advisory');

            fwrite($firstParent, "release\n");
            self::assertSame('ok', $this->readLine($firstParent));
            self::assertSame('ok', $this->readLine($secondParent));
            $this->waitForSuccessfulChild($firstPid);
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
            $this->releaseBarriers($barriers);
            $this->terminateAndWaitChildren($children);
            $this->closeBarriers($barriers);
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
        $children = [];
        $barriers = [];
        [$allParent, $allChild] = $this->socketPair();
        $allPid = $this->fork();

        if ($allPid === 0) {
            fclose($allParent);
            $this->runMarkAll((int) $user->getKey(), $allChild);
        }

        fclose($allChild);
        $children[] = $allPid;
        $barriers[] = $allParent;

        try {
            self::assertSame('cut:'.$expectedCut, $this->readLine($allParent));
            [$readParent, $readChild] = $this->socketPair();
            $readPid = $this->fork();

            if ($readPid === 0) {
                fclose($readParent);
                $this->runMarkAsRead($firstTargetId, $readChild);
            }

            fclose($readChild);
            $children[] = $readPid;
            $barriers[] = $readParent;
            self::assertSame('started', $this->readLine($readParent));
            self::assertSame('ok', $this->readLine($readParent));
            $this->waitForSuccessfulChild($readPid);

            $late = $this->sendNotification($user, 'post-cut');
            fwrite($allParent, "release\n");
            $markAllResult = json_decode($this->readLine($allParent), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($expectedCut, $markAllResult['sequence_cut']);
            self::assertSame(1, $markAllResult['count']);
            $this->waitForSuccessfulChild($allPid);

            self::assertNotNull($first->targets()->where('interface', 'admin')->value('read_at'));
            self::assertNotNull($second->targets()->where('interface', 'admin')->value('read_at'));
            self::assertNull($late->targets()->where('interface', 'admin')->value('read_at'));
        } finally {
            $this->releaseBarriers($barriers);
            $this->terminateAndWaitChildren($children);
            $this->closeBarriers($barriers);
            $this->deleteRecipientNotifications($user);
        }
    }

    private function runBlockedNotificationSend(int $userId, mixed $socket, string $label): never
    {
        $this->reconnectAfterFork();
        Queue::fake();
        app()->forgetInstance(NotificationService::class);
        app()->instance(
            NotificationPersistence::class,
            new BarrierNotificationPersistence(
                new DatabaseNotificationPersistence(app(NotificationInterfaceCursorStore::class)),
                $socket
            )
        );

        try {
            $this->sendNotification(User::query()->findOrFail($userId), $label);
            fwrite($socket, "ok\n");
            exit(0);
        } catch (Throwable $exception) {
            $this->exitWorkerWithError($socket, $exception);
        }
    }

    private function runNotificationSend(
        int $userId,
        mixed $socket,
        string $label,
        string $applicationName
    ): never {
        $this->reconnectAfterFork();
        Queue::fake();
        DB::selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
        fwrite($socket, "started\n");

        try {
            $this->sendNotification(User::query()->findOrFail($userId), $label);
            fwrite($socket, "ok\n");
            exit(0);
        } catch (Throwable $exception) {
            $this->exitWorkerWithError($socket, $exception);
        }
    }

    private function runMarkAsRead(string $targetId, mixed $socket): never
    {
        $this->reconnectAfterFork();
        fwrite($socket, "started\n");

        try {
            NotificationTarget::query()->findOrFail($targetId)->markAsRead();
            fwrite($socket, "ok\n");
            exit(0);
        } catch (Throwable $exception) {
            $this->exitWorkerWithError($socket, $exception);
        }
    }

    private function runMarkAll(int $userId, mixed $socket): never
    {
        $this->reconnectAfterFork();
        app()->instance(
            NotificationMarkAllReadGateway::class,
            new BarrierNotificationMarkAllReadGateway(
                new DatabaseNotificationMarkAllReadGateway,
                $socket
            )
        );

        try {
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
            $this->exitWorkerWithError($socket, $exception);
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

    private function waitForPostgresLock(string $applicationName, string $waitEvent): void
    {
        $deadline = microtime(true) + 5;

        do {
            $waiting = DB::table('pg_stat_activity')
                ->where('application_name', $applicationName)
                ->where('wait_event_type', 'Lock')
                ->where('wait_event', $waitEvent)
                ->exists();

            if ($waiting) {
                return;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        self::fail("Worker {$applicationName} did not reach {$waitEvent} lock wait.");
    }

    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new RuntimeException('Unable to create notification concurrency barrier.');
        }

        return $pair;
    }

    private function fork(): int
    {
        $pid = pcntl_fork();

        if ($pid < 0) {
            throw new RuntimeException('Unable to fork notification concurrency worker.');
        }

        return $pid;
    }

    private function readLine(mixed $socket): string
    {
        $read = [$socket];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 5, 0) !== 1) {
            throw new RuntimeException('Notification concurrency barrier timed out.');
        }

        $line = fgets($socket);

        if ($line === false) {
            throw new RuntimeException('Notification concurrency worker closed its barrier unexpectedly.');
        }

        return trim($line);
    }

    private function waitForSuccessfulChild(int $pid): void
    {
        $result = pcntl_waitpid($pid, $status);
        self::assertSame($pid, $result);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    private function releaseBarriers(array $barriers): void
    {
        foreach ($barriers as $barrier) {
            if (is_resource($barrier)) {
                @fwrite($barrier, "release\n");
            }
        }
    }

    private function closeBarriers(array $barriers): void
    {
        foreach ($barriers as $barrier) {
            if (is_resource($barrier)) {
                fclose($barrier);
            }
        }
    }

    private function terminateAndWaitChildren(array $children): void
    {
        foreach ($children as $pid) {
            $deadline = microtime(true) + 1;

            do {
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                if ($result === $pid || $result === -1) {
                    continue 2;
                }

                usleep(10000);
            } while (microtime(true) < $deadline);

            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
    }

    private function exitWorkerWithError(mixed $socket, Throwable $exception): never
    {
        fwrite($socket, 'error:'.$exception::class.':'.$exception->getMessage()."\n");
        exit(1);
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

final class BarrierNotificationMarkAllReadGateway implements NotificationMarkAllReadGateway
{
    public function __construct(
        private readonly NotificationMarkAllReadGateway $delegate,
        private readonly mixed $socket,
    ) {}

    public function markAllAsRead(
        NotificationInterface $interface,
        int $sequenceCut,
        Builder $visibleNotificationIds
    ): int {
        fwrite($this->socket, 'cut:'.$sequenceCut."\n");
        $command = fgets($this->socket);

        if ($command === false || trim($command) !== 'release') {
            throw new RuntimeException('Unexpected mark-all barrier command.');
        }

        return $this->delegate->markAllAsRead($interface, $sequenceCut, $visibleNotificationIds);
    }
}
