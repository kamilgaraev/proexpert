<?php
declare(strict_types=1);
namespace App\BusinessModules\Core\Reporting\Infrastructure\Queue;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher; use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\DeliverReportSubscriptionJob;
final readonly class LaravelReportSubscriptionDeliveryDispatcher implements ReportSubscriptionDeliveryDispatcher { public function dispatch(string $deliveryId,int $delaySeconds): void { DeliverReportSubscriptionJob::dispatch($deliveryId)->onQueue((string)config('reporting_subscriptions.queue'))->delay(max(0,$delaySeconds)); } }
