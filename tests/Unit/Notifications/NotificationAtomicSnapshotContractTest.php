<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;

final class NotificationAtomicSnapshotContractTest extends TestCase
{
    public function test_list_and_global_unread_aggregates_are_loaded_by_query_service_in_repeatable_read_transaction(): void
    {
        $service = $this->source(
            'app/BusinessModules/Features/Notifications/Services/NotificationQueryService.php'
        );

        self::assertStringContainsString('function listSnapshot(', $service);
        self::assertStringContainsString('DB::transaction(', $service);
        self::assertStringContainsString("DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')", $service);
        self::assertStringContainsString("DB::getDriverName() === 'pgsql'", $service);
        self::assertStringContainsString('->paginate($perPage)', $service);
        self::assertStringContainsString('unreadAggregatesForQuery(', $service);

        $isolationPosition = strpos($service, "DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')");
        $paginatePosition = strpos($service, '->paginate($perPage)');

        self::assertIsInt($isolationPosition);
        self::assertIsInt($paginatePosition);
        self::assertLessThan($paginatePosition, $isolationPosition);
    }

    public function test_controller_uses_atomic_snapshot_and_exposes_unread_aggregates_in_pagination_meta(): void
    {
        $controller = $this->source(
            'app/BusinessModules/Features/Notifications/Http/Controllers/NotificationController.php'
        );

        self::assertStringContainsString('$this->queryService->listSnapshot(', $controller);
        self::assertStringNotContainsString('$query->paginate($perPage)', $controller);
        self::assertStringContainsString("'unread_count' =>", $controller);
        self::assertStringContainsString("'unread_by_category' =>", $controller);
        self::assertStringContainsString("'unread_by_notification_type' =>", $controller);
        self::assertStringContainsString("'unread_by_type' =>", $controller);
    }

    public function test_customer_pagination_reuses_snapshot_unread_count_without_an_extra_query(): void
    {
        $controller = $this->source(
            'app/BusinessModules/Features/Notifications/Http/Controllers/NotificationController.php'
        );
        $customerMethod = substr($controller, (int) strpos($controller, 'private function customerPaginated'));

        self::assertStringContainsString("'unread_count' => \$unreadAggregates['count']", $customerMethod);
        self::assertStringNotContainsString('->count()', $customerMethod);
        self::assertStringNotContainsString('visibleTo($request)', $customerMethod);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);

        self::assertIsString($source);

        return $source;
    }
}
