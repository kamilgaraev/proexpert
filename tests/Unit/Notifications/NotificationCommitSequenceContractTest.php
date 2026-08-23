<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Services\NotificationSequenceDriverGuard;
use LogicException;
use PHPUnit\Framework\TestCase;

final class NotificationCommitSequenceContractTest extends TestCase
{
    public function test_postgres_advisory_locks_are_sorted_and_transaction_scoped(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Notifications/Services/DatabaseNotificationCommitSequencer.php'
        );

        self::assertStringContainsString('DB::transaction(', $source);
        self::assertStringContainsString("\$driver === 'pgsql'", $source);
        self::assertStringContainsString('sort($interfaceValues, SORT_STRING)', $source);
        self::assertStringContainsString(
            'pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            $source
        );
        self::assertStringContainsString('notification-target:{$user->getKey()}:{$interface}', $source);

        $lockPosition = strpos($source, 'pg_advisory_xact_lock');
        $callbackPosition = strrpos($source, 'return $callback();');
        self::assertIsInt($lockPosition);
        self::assertIsInt($callbackPosition);
        self::assertLessThan($callbackPosition, $lockPosition);
    }

    public function test_service_holds_commit_sequence_through_persistence_and_after_commit_dispatch_registration(): void
    {
        $source = $this->source(
            'app/BusinessModules/Features/Notifications/Services/NotificationService.php'
        );

        $sequencePosition = strpos($source, '$this->commitSequencer->run(');
        $persistencePosition = strpos($source, '$this->persistence->persist(', (int) $sequencePosition);
        $dispatchPosition = strpos($source, '$this->dispatch($notification)', (int) $persistencePosition);

        self::assertIsInt($sequencePosition);
        self::assertIsInt($persistencePosition);
        self::assertIsInt($dispatchPosition);
        self::assertLessThan($persistencePosition, $sequencePosition);
        self::assertLessThan($dispatchPosition, $persistencePosition);
    }

    public function test_notification_sequence_supports_only_postgres_in_every_environment(): void
    {
        $sequencer = $this->source(
            'app/BusinessModules/Features/Notifications/Services/DatabaseNotificationCommitSequencer.php'
        );
        $persistence = $this->source(
            'app/BusinessModules/Features/Notifications/Services/DatabaseNotificationPersistence.php'
        );
        $guard = $this->source(
            'app/BusinessModules/Features/Notifications/Services/NotificationSequenceDriverGuard.php'
        );

        self::assertStringContainsString('NotificationSequenceDriverGuard::assertSupported(', $sequencer);
        self::assertStringContainsString('NotificationSequenceDriverGuard::assertSupported(', $persistence);
        self::assertStringContainsString("\$driver === 'pgsql'", $guard);
        self::assertStringNotContainsString("\$driver === 'sqlite'", $guard);
        self::assertStringContainsString('throw new LogicException(', $guard);
    }

    public function test_driver_guard_accepts_postgres(): void
    {
        NotificationSequenceDriverGuard::assertSupported('pgsql');

        self::addToAssertionCount(1);
    }

    public function test_driver_guard_rejects_every_non_postgres_driver(): void
    {
        foreach (['sqlite', 'mysql'] as $driver) {
            try {
                NotificationSequenceDriverGuard::assertSupported($driver);
                self::fail("Driver {$driver} unexpectedly accepted");
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($source);

        return $source;
    }
}
