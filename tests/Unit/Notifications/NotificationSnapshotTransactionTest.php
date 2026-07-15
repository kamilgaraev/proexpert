<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Contracts\NotificationSnapshotDatabase;
use App\BusinessModules\Features\Notifications\Services\NotificationSnapshotTransactionRunner;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NotificationSnapshotTransactionTest extends TestCase
{
    public function test_repeatable_read_is_set_after_transaction_begins_and_before_list_callback(): void
    {
        $events = [];
        $database = $this->database($events, 'pgsql', 1);

        try {
            (new NotificationSnapshotTransactionRunner($database))->run(
                function () use (&$events): void {
                    $events[] = 'list_callback';

                    throw new RuntimeException('stop before database query');
                }
            );
            self::fail('The list callback must stop the test before pagination');
        } catch (RuntimeException $exception) {
            self::assertSame('stop before database query', $exception->getMessage());
        }

        self::assertSame(
            [
                'transaction',
                'driver',
                'level',
                'statement:SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                'list_callback',
            ],
            $events
        );
    }

    public function test_nested_postgres_transaction_fails_closed_before_list_callback(): void
    {
        $listCallbackCalled = false;
        $events = [];
        $database = $this->database($events, 'pgsql', 2);

        try {
            (new NotificationSnapshotTransactionRunner($database))->run(
                function () use (&$listCallbackCalled): void {
                    $listCallbackCalled = true;

                    throw new RuntimeException('nested transaction reached list callback');
                }
            );
            self::fail('Nested PostgreSQL snapshot must fail closed');
        } catch (LogicException $exception) {
            self::assertSame(
                'Notification snapshot requires an outermost PostgreSQL transaction',
                $exception->getMessage()
            );
        }

        self::assertFalse($listCallbackCalled);
        self::assertSame(['transaction', 'driver', 'level'], $events);
    }

    /**
     * @param  list<string>  $events
     */
    private function database(array &$events, string $driver, int $transactionLevel): NotificationSnapshotDatabase
    {
        return new class($events, $driver, $transactionLevel) implements NotificationSnapshotDatabase
        {
            /**
             * @param  list<string>  $events
             */
            public function __construct(
                private array &$events,
                private readonly string $driver,
                private readonly int $transactionLevel
            ) {}

            public function transaction(Closure $callback): mixed
            {
                $this->events[] = 'transaction';

                return $callback();
            }

            public function driverName(): string
            {
                $this->events[] = 'driver';

                return $this->driver;
            }

            public function transactionLevel(): int
            {
                $this->events[] = 'level';

                return $this->transactionLevel;
            }

            public function statement(string $sql): void
            {
                $this->events[] = 'statement:'.$sql;
            }
        };
    }
}
