<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Audit;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchBackoffPolicy;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AppendReportAuditEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $intentId)
    {
        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $intentId) !== 1) {
            throw new \InvalidArgumentException('report_audit_intent_id_invalid');
        }

        $this->onConnection('redis_reports');
        $this->onQueue('reports-audit');
    }

    public function handle(
        CoreReportAuditIntentConsumer $consumer,
        ReportAuditIntentStore $store,
    ): void {
        $leaseToken = $this->envelopeUuid();
        $claimedAt = $this->now();
        $lease = $store->claim(
            $this->intentId,
            $leaseToken,
            $claimedAt,
            $claimedAt->modify('+60 seconds'),
        );
        if ($lease === null) {
            return;
        }
        if ($lease->intentId !== $this->intentId || $lease->leaseToken !== $leaseToken) {
            throw new \LogicException('report_audit_lease_identity_invalid');
        }

        $intent = $store->loadLeased($this->intentId, $leaseToken);
        if ($intent->id !== $this->intentId || $intent->attemptCount !== $lease->attemptCount) {
            throw new \LogicException('report_audit_intent_identity_invalid');
        }

        try {
            $consumer->append($intent);
            $store->acknowledge($this->intentId, $leaseToken, $this->now());
        } catch (Throwable $throwable) {
            try {
                $this->recordFailure($store, $intent, $leaseToken);
            } catch (Throwable $failure) {
                $this->logFailureRecordingError($failure);
            }

            throw $throwable;
        }
    }

    public function failed(?Throwable $throwable): void
    {
        $leaseToken = $this->nullableEnvelopeUuid();
        if ($leaseToken === null) {
            return;
        }

        try {
            $store = app(ReportAuditIntentStore::class);
            $intent = $store->loadLeased($this->intentId, $leaseToken);
            $this->recordFailure($store, $intent, $leaseToken);
        } catch (Throwable $failure) {
            $this->logFailureRecordingError($failure);
        }
    }

    private function recordFailure(
        ReportAuditIntentStore $store,
        ReportAuditIntent $intent,
        string $leaseToken,
    ): void {
        $occurredAt = $this->now();
        $store->failDelivery(
            $this->intentId,
            $leaseToken,
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            $occurredAt,
            (new ReportDispatchBackoffPolicy)->nextAttemptAt(
                $intent->attemptCount,
                $occurredAt,
            ),
        );
        if ($intent->attemptCount === 12) {
            Log::critical('report_audit_delivery_dead_lettered', [
                'intent_id' => $this->intentId,
            ]);
        }
    }

    private function logFailureRecordingError(Throwable $failure): void
    {
        Log::error('report_audit_delivery_failure_recording_failed', [
            'intent_id' => $this->intentId,
            'error_type' => $failure::class,
        ]);
    }

    private function envelopeUuid(): string
    {
        return $this->nullableEnvelopeUuid()
            ?? throw new \LogicException('report_audit_queue_envelope_invalid');
    }

    private function nullableEnvelopeUuid(): ?string
    {
        $uuid = $this->job?->uuid();

        return is_string($uuid) && Str::isUuid($uuid)
            ? strtolower($uuid)
            : null;
    }

    private function now(): \DateTimeImmutable
    {
        return Carbon::now('UTC')->toDateTimeImmutable();
    }
}
