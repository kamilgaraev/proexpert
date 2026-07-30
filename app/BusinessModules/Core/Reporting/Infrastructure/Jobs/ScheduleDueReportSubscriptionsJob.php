<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Jobs;

use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionScheduleCalculator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

final class ScheduleDueReportSubscriptionsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(
        ReportSubscriptionStore $subscriptions,
        ReportSubscriptionDeliveryStore $deliveries,
        ReportSubscriptionDeliveryDispatcher $dispatcher,
        ReportSubscriptionScheduleCalculator $schedule,
    ): void {
        $ids = DB::transaction(function () use ($subscriptions, $deliveries, $schedule): array {
            $now = new DateTimeImmutable;
            $ids = [];

            foreach ($subscriptions->selectDueLocked($now, (int) config('reporting_subscriptions.scheduler_batch_size')) as $subscription) {
                if (
                    $subscription->nextRunAt === null
                    || ! hash_equals(hash('sha256', $subscription->executionInputBytes), $subscription->executionInputHash->value)
                ) {
                    $subscriptions->disableLocked($subscription, 'definition_changed');

                    continue;
                }

                $delivery = $deliveries->createCalendarScheduledLocked(
                    $subscription,
                    $subscription->nextRunAt,
                    $subscription->executionInputBytes,
                    $subscription->executionInputHash,
                    $subscription->transitionVersion,
                );

                $subscriptions->advanceNextRunLocked(
                    $subscription,
                    $schedule->next($subscription, $subscription->nextRunAt),
                );

                if ($delivery !== null) {
                    $ids[] = $delivery->id;
                }
            }

            return $ids;
        });

        foreach ($ids as $id) {
            $dispatcher->dispatch($id, 0);
        }
    }
}
