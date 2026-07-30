<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Domain\Contracts\InAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionEventRecorder;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionDeliveryStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ReportSubscriptionDeliveryProcessor
{
    public function __construct(
        private ReportSubscriptionDeliveryStore $deliveries,
        private ReportSubscriptionExecutionContextFactory $contexts,
        private ReportSubscriptionDeliveryDispatcher $dispatcher,
        private CreateReportRunAction $runs,
        private GetReportRunAction $getRuns,
        private CreateReportExportAction $exports,
        private GetReportExportAction $getExports,
        private InAppReportSubscriptionNotifier $notifier,
        private ReportSubscriptionEventRecorder $events,
        private ReportSubscriptionPeriodResolver $periods,
    ) {}

    public function process(string $id): void
    {
        $dispatches = DB::transaction(function () use ($id): array {
            [$delivery, $subscription] = $this->deliveries->lockWithSubscription($id);
            $now = new DateTimeImmutable;

            if ($delivery->executionExpiresAt <= $now) {
                if (! in_array(
                    $delivery->status,
                    [
                        ReportSubscriptionDeliveryStatus::NOTIFIED,
                        ReportSubscriptionDeliveryStatus::FAILED,
                        ReportSubscriptionDeliveryStatus::EXPIRED,
                    ],
                    true,
                )) {
                    $this->deliveries->markExpiredLocked($delivery);
                }

                return [];
            }

            if (
                $delivery->status === ReportSubscriptionDeliveryStatus::SCHEDULED
                && $delivery->retryAt !== null
                && $delivery->retryAt > $now
            ) {
                return [[$delivery->id, max(1, $delivery->retryAt->getTimestamp() - $now->getTimestamp())]];
            }

            try {
                $input = $delivery->executionInput();
                $context = $this->contexts->forSubscription($subscription, ReportOperation::RUN);

                if ($delivery->status === ReportSubscriptionDeliveryStatus::SCHEDULED) {
                    $this->deliveries->beginAttemptLocked($delivery);

                    $run = $this->runs->handle(
                        $context,
                        $input->runData($this->periods->asOf($input, $delivery->scheduledFor)),
                        $delivery->runIdempotencyKey(),
                    );

                    $this->deliveries->attachRunLocked($delivery, $run->id);
                    $this->record($context, 'report_subscription_run_requested', $delivery);

                    return [[$delivery->id, 0]];
                }

                if ($delivery->status === ReportSubscriptionDeliveryStatus::BUILDING_RUN) {
                    $run = $this->getRuns->handle($context, (string) $delivery->runId);

                    if (in_array($run->status, [ReportRunStatus::QUEUED, ReportRunStatus::MATERIALIZING], true)) {
                        return [[$delivery->id, $this->delay($run->pollAfterMs)]];
                    }

                    if ($run->status !== ReportRunStatus::READY) {
                        return $this->retry($delivery, 'REPORT_RUN_FAILED');
                    }

                    $export = $this->exports->handle(
                        $context,
                        $run->id,
                        $input->exportData(),
                        $delivery->exportIdempotencyKey(),
                    );

                    $this->deliveries->attachExportLocked($delivery, $export->id);
                    $this->record($context, 'report_subscription_export_requested', $delivery);

                    return [[$delivery->id, 0]];
                }

                if ($delivery->status === ReportSubscriptionDeliveryStatus::BUILDING_EXPORT) {
                    $export = $this->getExports->handle($context, (string) $delivery->exportId);

                    if (in_array(
                        $export->status,
                        [ReportExportStatus::QUEUED, ReportExportStatus::RUNNING, ReportExportStatus::UPLOADING],
                        true,
                    )) {
                        return [[$delivery->id, $this->delay($export->pollAfterMs)]];
                    }

                    if ($export->status !== ReportExportStatus::READY) {
                        return $this->retry($delivery, 'REPORT_EXPORT_FAILED');
                    }

                    $this->deliveries->markReadyLocked($delivery);
                    $this->record($context, 'report_subscription_export_ready', $delivery);

                    return [[$delivery->id, 0]];
                }

                if ($delivery->status === ReportSubscriptionDeliveryStatus::READY) {
                    $export = $this->getExports->handle($context, (string) $delivery->exportId);
                    $key = $delivery->notificationIdempotencyKey();
                    $receipt = $this->notifier->notify($context, $subscription, $delivery, $export, $key);

                    $this->deliveries->markNotifiedLocked(
                        $delivery,
                        $receipt->id,
                        new Sha256Hash($key->hash),
                    );
                    $this->record($context, 'report_subscription_notified', $delivery);
                }

                return [];
            } catch (Throwable) {
                return $this->retry($delivery, 'REPORT_INTERNAL_ERROR');
            }
        });

        foreach ($dispatches as [$deliveryId, $delaySeconds]) {
            $this->dispatcher->dispatch($deliveryId, $delaySeconds);
        }
    }

    private function delay(?int $ms): int
    {
        return (int) ceil(
            max(
                (int) config('reporting_subscriptions.poll_min_ms'),
                min((int) config('reporting_subscriptions.poll_max_ms'), $ms ?? 1000),
            ) / 1000,
        );
    }

    private function retry(ReportSubscriptionDelivery $delivery, string $code): array
    {
        if ($delivery->attempt >= (int) config('reporting_subscriptions.max_attempts')) {
            $this->deliveries->markFailedLocked($delivery, $code);

            return [];
        }

        $backoff = config('reporting_subscriptions.backoff_seconds')[$delivery->attempt] ?? 1800;
        $this->deliveries->rescheduleRetryLocked(
            $delivery,
            (new DateTimeImmutable)->modify('+'.$backoff.' seconds'),
            $code,
        );

        return [[$delivery->id, (int) $backoff]];
    }

    private function record(
        ReportExecutionContext $context,
        string $eventCode,
        ReportSubscriptionDelivery $delivery,
    ): void {
        $this->events->record(
            $eventCode,
            $context,
            'report_subscription_delivery',
            $delivery->id,
            $delivery->subscriptionVersion,
            [
                'delivery_status' => $delivery->status->value,
                'report_subscription_id' => $delivery->subscriptionId,
                'report_subscription_trigger' => $delivery->trigger->value,
            ],
        );
    }
}
