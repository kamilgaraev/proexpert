<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;

final class NotificationCursorStateContractTest extends TestCase
{
    public function test_undeployed_target_migration_creates_and_backfills_per_user_interface_cursor_state(): void
    {
        $migration = $this->source(
            'app/BusinessModules/Features/Notifications/migrations/2026_07_15_000001_create_notification_targets_table.php'
        );

        self::assertStringContainsString("Schema::create('notification_interface_cursors'", $migration);
        self::assertStringContainsString("\$table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete()", $migration);
        self::assertStringContainsString("\$table->primary(['recipient_user_id', 'interface'])", $migration);
        self::assertStringContainsString("DB::table('notification_interface_cursors')->insertUsing(", $migration);
        self::assertStringContainsString('MAX(notification_targets.sequence)', $migration);
        self::assertStringContainsString("Schema::dropIfExists('notification_interface_cursors')", $migration);
    }

    public function test_persistence_advances_cursor_after_targets_inside_the_transaction(): void
    {
        $persistence = $this->source(
            'app/BusinessModules/Features/Notifications/Services/DatabaseNotificationPersistence.php'
        );

        $targetsPosition = strpos($persistence, '$notification->targets()->createMany($targets)');
        $cursorPosition = strpos($persistence, '$this->cursorStore->advance($user, $notification)');

        self::assertIsInt($targetsPosition);
        self::assertIsInt($cursorPosition);
        self::assertLessThan($cursorPosition, $targetsPosition);
    }

    public function test_cursor_store_reads_one_state_row_and_upserts_latest_target_sequences(): void
    {
        $store = $this->source(
            'app/BusinessModules/Features/Notifications/Services/NotificationInterfaceCursorStore.php'
        );

        self::assertStringContainsString("DB::table('notification_interface_cursors')", $store);
        self::assertStringContainsString("->where('recipient_user_id', \$user->getKey())", $store);
        self::assertStringContainsString("->where('interface', \$interface->value)", $store);
        self::assertStringContainsString("->value('latest_sequence')", $store);
        self::assertStringContainsString("->where('notification_id', \$notification->getKey())", $store);
        self::assertStringContainsString('->upsert(', $store);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($source);

        return $source;
    }
}
