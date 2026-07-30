<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Subscriptions\ListReportSubscriptionsHandler;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ReportSubscriptionPageIsolationTest extends TestCase
{
    public function test_subscription_list_returns_its_dedicated_page_without_rows_page_dependency(): void
    {
        $method = new ReflectionMethod(ListReportSubscriptionsHandler::class, 'handle');
        $source = file_get_contents((new ReflectionClass(ListReportSubscriptionsHandler::class))->getFileName());

        self::assertSame(ReportSubscriptionPage::class, $method->getReturnType()?->getName());
        self::assertIsString($source);
        self::assertStringNotContainsString(ReportPage::class, $source);
    }

    public function test_subscription_cursor_page_does_not_depend_on_dashboard_or_export_flows(): void
    {
        $source = file_get_contents((new ReflectionClass(ListReportSubscriptionsHandler::class))->getFileName());

        self::assertIsString($source);
        self::assertStringNotContainsString('Application\\Dashboard\\', $source);
        self::assertStringNotContainsString('Application\\Exports\\', $source);
        self::assertStringNotContainsString('Domain\\DTO\\ReportDashboard', $source);
        self::assertStringNotContainsString('Domain\\DTO\\ReportExport', $source);
    }
}
