<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\InAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionEventRecorder;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionDeliveryStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
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
        $runWork = null;
        $dispatches = DB::transaction(function () use ($id, &$runWork): array {
            [$delivery, $subscription] = $this->deliveries->lockWithSubscription($id);
            $now = new DateTimeImmutable;

            if (in_array(
                $delivery->status,
                [
                    ReportSubscriptionDeliveryStatus::NOTIFIED,
                    ReportSubscriptionDeliveryStatus::FAILED,
                    ReportSubscriptionDeliveryStatus::EXPIRED,
                ],
                true,
            )) {
                return [];
            }

            if ($delivery->executionExpiresAt <= $now) {
                $this->deliveries->markExpiredLocked($delivery);

                return [];
            }

            if (
                $delivery->status === ReportSubscriptionDeliveryStatus::SCHEDULED
                && $delivery->retryAt !== null
                && $delivery->retryAt > $now
            ) {
                return [[$delivery->id, max(1, $delivery->retryAt->getTimestamp() - $now->getTimestamp())]];
            }

            if ($delivery->status === ReportSubscriptionDeliveryStatus::READY) {
                return $this->notifyReady($delivery, $subscription);
            }

            try {
                if ($delivery->status === ReportSubscriptionDeliveryStatus::SCHEDULED
                    || ($delivery->status === ReportSubscriptionDeliveryStatus::BUILDING_RUN && $delivery->runId === null)) {
                    if ($delivery->status === ReportSubscriptionDeliveryStatus::SCHEDULED) {
                        $this->deliveries->beginAttemptLocked($delivery);
                    }
                    $runWork = [$delivery, $subscription];

                    return [];
                }
                $input = $delivery->executionInput();
                $context = $this->contexts->forSubscription($subscription, ReportOperation::RUN, $input);

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

                return [];
            } catch (Throwable $exception) {
                $this->logFailure($delivery, $exception);

                return $this->retry($delivery, 'REPORT_INTERNAL_ERROR');
            }
        });

        if ($runWork !== null) {
            $dispatches = [...$dispatches, ...$this->createRunOutsideTransaction(...$runWork)];
        }
        foreach ($dispatches as [$deliveryId, $delaySeconds]) {
            $this->dispatcher->dispatch($deliveryId, $delaySeconds);
        }
    }

    private function createRunOutsideTransaction(ReportSubscriptionDelivery $delivery, ReportSubscription $subscription): array
    {
        try {
            $input = $delivery->executionInput();
            $context = $this->contexts->forSubscription($subscription, ReportOperation::RUN, $input);
            $run = $this->runs->handle(
                $context,
                $this->periods->runData($input, $delivery->scheduledFor),
                $delivery->runIdempotencyKey(),
            );
        } catch (Throwable $exception) {
            return DB::transaction(function () use ($delivery, $exception): array {
                [$current] = $this->deliveries->lockWithSubscription($delivery->id);
                if ($current->status !== ReportSubscriptionDeliveryStatus::BUILDING_RUN || $current->runId !== null) {
                    return [];
                }
                if ($current->executionExpiresAt <= new DateTimeImmutable) {
                    $this->deliveries->markExpiredLocked($current);

                    return [];
                }
                $this->logFailure($current, $exception);

                return $this->retry($current, $exception instanceof ReportContractException
                    ? $exception->errorCode->value : ReportErrorCode::REPORT_INTERNAL_ERROR->value);
            });
        }

        $attached = null;
        $dispatches = DB::transaction(function () use ($delivery, $run, &$attached): array {
            [$current] = $this->deliveries->lockWithSubscription($delivery->id);
            if ($current->status !== ReportSubscriptionDeliveryStatus::BUILDING_RUN) {
                return [];
            }
            if ($current->executionExpiresAt <= new DateTimeImmutable) {
                $this->deliveries->markExpiredLocked($current);

                return [];
            }
            if (! hash_equals($current->executionInputHash->value, $delivery->executionInputHash->value)
                || ($current->runId !== null && $current->runId !== $run->id)) {
                throw new LogicException('report_subscription_delivery_concurrent_change');
            }
            if ($current->runId === null) {
                $this->deliveries->attachRunLocked($current, $run->id);
                $attached = $current;
            }

            return [[$current->id, 0]];
        });
        if ($attached !== null) {
            $this->record($context, 'report_subscription_run_requested', $attached);
        }

        return $dispatches;
    }

    private function notifyReady(ReportSubscriptionDelivery $delivery, ReportSubscription $subscription): array
    {
        try {
            $context = $this->contexts->forSubscription($subscription, ReportOperation::RUN, $delivery->executionInput());
            $export = $this->getExports->handle($context, (string) $delivery->exportId);
            $key = $delivery->notificationIdempotencyKey();
            $receipt = $this->notifier->notify($context, $subscription, $delivery, $export, $key);
        } catch (Throwable $exception) {
            $this->logFailure($delivery, $exception);

            return [[$delivery->id, $this->delay((int) config('reporting_subscriptions.poll_max_ms'))]];
        }

        if ($receipt === null) {
            return [[$delivery->id, $this->delay((int) config('reporting_subscriptions.poll_max_ms'))]];
        }

        $this->deliveries->markNotifiedLocked($delivery, $receipt->id, new Sha256Hash($key->hash));
        $this->record($context, 'report_subscription_notified', $delivery);

        return [];
    }

    private function logFailure(ReportSubscriptionDelivery $delivery, Throwable $exception): void
    {
        Log::warning('report_subscription_delivery_failed', [
            'delivery_id' => $delivery->id,
            'subscription_id' => $delivery->subscriptionId,
            'error_code' => $exception instanceof ReportContractException
                ? $exception->errorCode->value
                : ReportErrorCode::REPORT_INTERNAL_ERROR->value,
        ]);
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
