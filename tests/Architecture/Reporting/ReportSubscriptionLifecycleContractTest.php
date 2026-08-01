<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;

final class ReportSubscriptionLifecycleContractTest extends TestCase
{
    public function test_calendar_and_manual_delivery_uniqueness_are_separated(): void
    {
        $migration = $this->source('database/migrations/2026_07_26_000007_create_report_subscriptions_tables.php');

        self::assertStringNotContainsString('report_subscription_delivery_schedule_unique', $migration);
        self::assertStringContainsString(
            "CREATE UNIQUE INDEX report_subscription_calendar_schedule_unique ON report_subscription_deliveries (subscription_id, scheduled_for) WHERE trigger = 'calendar'",
            $migration,
        );
        self::assertStringContainsString('report_subscription_manual_idempotency_unique', $migration);
    }

    public function test_retry_backoff_retention_and_ttl_contracts_are_enforced(): void
    {
        $processor = $this->source('app/BusinessModules/Core/Reporting/Application/Subscriptions/ReportSubscriptionDeliveryProcessor.php');
        $store = $this->source('app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportSubscriptionDeliveryStore.php');

        self::assertStringContainsString('$delivery->retryAt !== null', $processor);
        self::assertStringContainsString('$delivery->retryAt > $now', $processor);
        self::assertStringContainsString('reporting_subscriptions.execution_ttl_seconds', $store);

        $receiptDeletePosition = strpos($store, "DB::table('report_subscription_notification_receipts')");
        $deliveryDestroyPosition = strpos($store, 'ReportSubscriptionDeliveryRecord::destroy');

        self::assertIsInt($receiptDeletePosition);
        self::assertIsInt($deliveryDestroyPosition);
        self::assertLessThan($deliveryDestroyPosition, $receiptDeletePosition);
    }

    public function test_delivery_processor_records_subscription_lifecycle_events(): void
    {
        $processor = $this->source('app/BusinessModules/Core/Reporting/Application/Subscriptions/ReportSubscriptionDeliveryProcessor.php');

        self::assertStringContainsString('ReportSubscriptionEventRecorder', $processor);
        self::assertStringContainsString('report_subscription_run_requested', $processor);
        self::assertStringContainsString('report_subscription_export_requested', $processor);
        self::assertStringContainsString('report_subscription_export_ready', $processor);
        self::assertStringContainsString('report_subscription_notified', $processor);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$path);

        self::assertIsString($source);

        return $source;
    }
}
