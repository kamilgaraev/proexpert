<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationTargetMigrationContractTest extends TestCase
{
    #[Test]
    public function migration_defines_isolated_interface_state_and_constraints(): void
    {
        $migration = $this->migrationSource();

        self::assertStringContainsString("uuid('id')->primary()", $migration);
        self::assertStringContainsString("foreignUuid('notification_id')", $migration);
        self::assertStringContainsString('cascadeOnDelete()', $migration);
        self::assertStringContainsString("string('interface', 20)", $migration);
        self::assertStringContainsString("timestampTz('read_at')->nullable()", $migration);
        self::assertStringContainsString("timestampTz('dismissed_at')->nullable()", $migration);
        self::assertStringContainsString("string('websocket_status', 20)->default('pending')", $migration);
        self::assertStringContainsString("timestampTz('websocket_delivered_at')->nullable()", $migration);
        self::assertStringContainsString("text('websocket_last_error')->nullable()", $migration);
        self::assertStringContainsString("unique(['notification_id', 'interface'])", $migration);
        self::assertStringContainsString("CHECK (interface IN ('admin', 'lk', 'mobile', 'customer'))", $migration);
        self::assertStringContainsString("index(['interface', 'dismissed_at', 'read_at'])", $migration);
        self::assertStringContainsString("index('notification_id')", $migration);
    }

    #[Test]
    public function migration_backfills_known_targets_in_idempotent_batches(): void
    {
        $migration = $this->migrationSource();

        self::assertStringContainsString('chunkById(500', $migration);
        self::assertStringContainsString("\$interface = 'admin'", $migration);
        self::assertStringContainsString("['admin', 'lk', 'mobile', 'customer']", $migration);
        self::assertStringContainsString('JSON_THROW_ON_ERROR', $migration);
        self::assertStringContainsString('catch (\JsonException)', $migration);
        self::assertStringContainsString('continue;', $migration);
        self::assertStringContainsString("'read_at' => \$notification->read_at", $migration);
        self::assertStringContainsString('insertOrIgnore($targets)', $migration);
    }

    #[Test]
    public function migration_accepts_only_top_level_json_objects_for_legacy_targets(): void
    {
        $migration = $this->migrationSource();

        self::assertStringContainsString('json_decode($data, false, flags: JSON_THROW_ON_ERROR)', $migration);
        self::assertStringContainsString('if (! $data instanceof \stdClass)', $migration);
        self::assertStringContainsString("property_exists(\$data, 'interface')", $migration);
        self::assertStringContainsString('is_string($data->interface)', $migration);
        self::assertStringNotContainsString('json_decode($data, true', $migration);
        self::assertStringNotContainsString('is_array($data)', $migration);
    }

    #[Test]
    public function migration_blocks_notification_writes_before_uuid_cursor_backfill(): void
    {
        $migration = $this->migrationSource();
        $lock = "DB::statement('LOCK TABLE notifications IN SHARE ROW EXCLUSIVE MODE')";
        $lockPosition = strpos($migration, $lock);
        $backfillPosition = strpos($migration, 'chunkById(500');

        self::assertNotFalse($lockPosition, 'PostgreSQL backfill must lock notification writes.');
        self::assertNotFalse($backfillPosition, 'Batch backfill cursor is missing.');
        self::assertLessThan($backfillPosition, $lockPosition, 'Write lock must be acquired before UUID cursor traversal.');
        self::assertStringNotContainsString('$withinTransaction = false', $migration);
    }

    #[Test]
    public function notification_model_scope_filters_through_the_typed_target(): void
    {
        $model = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Models/Notification.php'
        );

        self::assertStringContainsString("where('interface', \$interface->value)", $model);
    }

    private function migrationSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3)
            .'/app/BusinessModules/Features/Notifications/migrations/'
            .'2026_07_15_000001_create_notification_targets_table.php'
        );
    }
}
