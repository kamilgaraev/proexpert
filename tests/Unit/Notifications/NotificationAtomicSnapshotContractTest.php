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
        $runner = $this->source(
            'app/BusinessModules/Features/Notifications/Services/NotificationSnapshotTransactionRunner.php'
        );

        self::assertStringContainsString('function listSnapshot(', $service);
        self::assertStringContainsString('$this->snapshotTransactionRunner->run(', $service);
        self::assertStringContainsString('->paginate($perPage)', $service);
        self::assertStringContainsString('unreadAggregatesForQuery(', $service);
        self::assertStringContainsString('snapshotSequenceFor(', $service);
        self::assertStringContainsString('->transaction(', $runner);
        self::assertStringContainsString("=== 'pgsql'", $runner);
        self::assertStringContainsString('transactionLevel() !== 1', $runner);
        self::assertStringContainsString("->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')", $runner);
        self::assertMatchesRegularExpression(
            "/orderByDesc\\('created_at'\\)\\s*->orderByDesc\\('id'\\)/",
            $service
        );

        $cursorMethod = substr(
            $service,
            (int) strpos($service, 'private function snapshotSequenceFor'),
            (int) strpos($service, 'private function authenticatedUser')
                - (int) strpos($service, 'private function snapshotSequenceFor')
        );
        self::assertStringContainsString("where('interface', \$interface->value)", $cursorMethod);
        self::assertStringContainsString('->forUser($user)', $cursorMethod);
        self::assertStringContainsString("->max('sequence')", $cursorMethod);
        self::assertStringNotContainsString('organization_id', $cursorMethod);
        self::assertStringNotContainsString('dismissed_at', $cursorMethod);
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
        self::assertStringContainsString("'snapshot_sequence' =>", $controller);
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

    public function test_unread_count_and_mark_all_share_sequence_cuts_with_the_snapshot_runner(): void
    {
        $service = $this->source(
            'app/BusinessModules/Features/Notifications/Services/NotificationQueryService.php'
        );
        $controller = $this->source(
            'app/BusinessModules/Features/Notifications/Http/Controllers/NotificationController.php'
        );

        $unreadMethod = $this->methodSource($service, 'public function unreadAggregatesTo', 'public function visibleFor');
        self::assertStringContainsString('$this->snapshotTransactionRunner->run(', $unreadMethod);
        self::assertStringContainsString("'snapshot_sequence' =>", $unreadMethod);
        self::assertStringContainsString('$this->snapshotSequenceFor($user, $interface)', $unreadMethod);

        $markAllMethod = $this->methodSource($service, 'public function markAllAsRead', '/**');
        self::assertStringContainsString('$this->snapshotTransactionRunner->run(', $markAllMethod);
        self::assertStringContainsString('$sequenceCut = $this->snapshotSequenceFor($user, $interface)', $markAllMethod);
        self::assertStringContainsString("where('sequence', '<=', \$sequenceCut)", $markAllMethod);
        self::assertStringContainsString('new NotificationMarkAllReadResult(', $markAllMethod);
        self::assertStringContainsString("'sequence_cut' => \$result->sequenceCut", $controller);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);

        self::assertIsString($source);

        return $source;
    }

    private function methodSource(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        self::assertIsInt($startPosition);
        $endPosition = strpos($source, $end, $startPosition + strlen($start));
        self::assertIsInt($endPosition);

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
