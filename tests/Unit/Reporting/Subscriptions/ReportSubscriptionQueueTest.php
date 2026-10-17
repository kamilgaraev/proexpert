<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Subscriptions;

use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\DeliverReportSubscriptionJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\ExpireReportSubscriptionExecutionsJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\PruneReportSubscriptionDeliveriesJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\ScheduleDueReportSubscriptionsJob;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class ReportSubscriptionQueueTest extends TestCase
{
    public function test_delivery_and_scheduled_jobs_use_the_existing_reports_worker_with_other_default_connection(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['queue' => ['default' => 'database'],
            'reporting_subscriptions' => require dirname(__DIR__, 4).'/config/reporting_subscriptions.php']));
        Container::setInstance($container);
        try {
            $horizon = require dirname(__DIR__, 4).'/config/horizon.php';
            foreach ([new DeliverReportSubscriptionJob('01J00000000000000000000000'), new ScheduleDueReportSubscriptionsJob,
                new ExpireReportSubscriptionExecutionsJob, new PruneReportSubscriptionDeliveriesJob] as $job) {
                self::assertSame('redis_reports', $job->connection);
                self::assertSame('reports', $job->queue);
                self::assertSame($job->connection, $horizon['environments']['production']['supervisor-reports']['connection']);
                self::assertContains($job->queue, $horizon['environments']['production']['supervisor-reports']['queue']);
            }
        } finally {
            Container::setInstance($previous);
        }
    }
}
