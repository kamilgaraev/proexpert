<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration;
use App\BusinessModules\Core\Reporting\Infrastructure\Dispatch\LaravelReportDispatchIntentPublisher;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class ReportDispatchIntentPublisher
{
    public function __construct(
        private readonly ReportDispatchIntentStore $store,
        private readonly LaravelReportDispatchIntentPublisher $transport,
        private readonly ReportDispatchBackoffPolicy $backoff,
        private readonly ReportExecutionTelemetry $telemetry,
        private readonly ReportExecutionRuntimeConfiguration $runtime,
    ) {}

    public function publishBatch(int $limit, DateTimeImmutable $occurredAt): ReportDispatchPublishSummary
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('report_dispatch_batch_size_invalid');
        }

        $leaseToken = (string) Str::uuid();
        $leases = $this->store->claimDue(
            $limit,
            $occurredAt,
            $occurredAt->modify("+{$this->runtime->dispatchLeaseSeconds} seconds"),
            $leaseToken,
        );
        $published = 0;
        $retryScheduled = 0;
        $deadLettered = 0;
        $skipped = 0;

        foreach ($leases as $lease) {
            if (! $lease instanceof ReportDispatchLease) {
                throw new LogicException('report_dispatch_claim_invalid');
            }
            if ($lease->leaseToken !== $leaseToken) {
                $skipped++;

                continue;
            }

            try {
                $this->transport->publish($lease->intent);
                $this->store->markPublished($lease->intent->id, $leaseToken, $occurredAt);
                $this->record($lease->intent, 'published', $occurredAt);
                $published++;
            } catch (Throwable) {
                $this->store->markPublicationFailed(
                    $lease->intent->id,
                    $leaseToken,
                    ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                    $occurredAt,
                    $this->backoff->nextAttemptAt($lease->intent->attemptCount, $occurredAt),
                );
                if ($lease->intent->attemptCount === $this->runtime->dispatchMaxAttempts) {
                    $this->record($lease->intent, 'dead_letter', $occurredAt);
                    $deadLettered++;
                } else {
                    $this->record($lease->intent, 'retry', $occurredAt);
                    $retryScheduled++;
                }
            }
        }

        return new ReportDispatchPublishSummary(
            count($leases),
            count($leases) - $skipped,
            $published,
            $retryScheduled,
            $deadLettered,
            $skipped,
        );
    }

    private function record(
        ReportDispatchIntent $intent,
        string $outcome,
        DateTimeImmutable $occurredAt,
    ): void {
        $this->telemetry->dispatchIntent(
            $intent->aggregate->value,
            $intent->topic->value,
            $outcome,
            max(0.0, (float) ($occurredAt->format('U.u') - $intent->occurredAt->format('U.u'))),
        );
    }
}
