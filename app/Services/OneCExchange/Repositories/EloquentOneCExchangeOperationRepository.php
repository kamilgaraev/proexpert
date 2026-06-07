<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Repositories;

use App\Models\OneCExchangeOperation;
use App\Services\OneCExchange\Contracts\OneCExchangeOperationRepositoryInterface;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryAttempt;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryOperation;
use App\Services\OneCExchange\OneCExchangeJournalService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class EloquentOneCExchangeOperationRepository implements OneCExchangeOperationRepositoryInterface
{
    private const CLAIMABLE_STATUSES = [
        'queued',
        'retry_scheduled',
    ];

    public function __construct(private readonly OneCExchangeJournalService $journal)
    {
    }

    public function claimNextDue(DateTimeImmutable $now): ?OneCExchangeDeliveryOperation
    {
        $claimedId = DB::transaction(function () use ($now): ?int {
            $dueAt = CarbonImmutable::instance($now);
            $processingTimeoutMinutes = max(1, (int) config('one_c_exchange.delivery.processing_timeout_minutes', 15));
            $staleProcessingStartedBefore = $dueAt->subMinutes($processingTimeoutMinutes);

            $operation = OneCExchangeOperation::query()
                ->where(static function ($query) use ($dueAt, $staleProcessingStartedBefore): void {
                    $query->where(static function ($claimable) use ($dueAt): void {
                        $claimable
                            ->whereIn('status', self::CLAIMABLE_STATUSES)
                            ->where(static function ($retry) use ($dueAt): void {
                                $retry
                                    ->whereNull('next_retry_at')
                                    ->orWhere('next_retry_at', '<=', $dueAt);
                            });
                    })->orWhere(static function ($stale) use ($staleProcessingStartedBefore): void {
                        $stale
                            ->where('status', 'processing')
                            ->whereNotNull('started_at')
                            ->where('started_at', '<=', $staleProcessingStartedBefore);
                    });
                })
                ->orderByRaw('CASE WHEN next_retry_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (!$operation instanceof OneCExchangeOperation) {
                return null;
            }

            $operation->update([
                'status' => 'processing',
                'started_at' => $dueAt,
                'finished_at' => null,
                'last_attempt_at' => $dueAt,
            ]);

            return (int) $operation->id;
        });

        if ($claimedId === null) {
            return null;
        }

        $operation = OneCExchangeOperation::query()->findOrFail($claimedId);

        return $this->mapOperation($operation);
    }

    public function recordAttempt(OneCExchangeDeliveryOperation $operation, OneCExchangeDeliveryAttempt $attempt): void
    {
        $model = OneCExchangeOperation::query()->findOrFail($operation->id);

        $this->journal->recordAttempt($model, [
            'status' => $attempt->status,
            'failure_type' => $attempt->failureType,
            'transport_status' => $attempt->transportStatus,
            'retryable' => $attempt->retryable,
            'next_retry_at' => $attempt->nextRetryAt,
            'safe_error_code' => $attempt->safeErrorCode,
            'safe_error_message' => $attempt->safeErrorMessage,
            'request' => $attempt->safeRequestPreview,
            'response' => $attempt->safeResponsePreview,
            'duration_ms' => $attempt->durationMs,
            'source_is_actual' => $operation->sourceIsActual(),
        ]);

        $updates = [];

        if ($attempt->externalId !== null) {
            $updates['external_id'] = $attempt->externalId;
        }

        if ($attempt->accountingStatus !== null) {
            $updates['accounting_status'] = $attempt->accountingStatus;
        }

        if ($updates !== []) {
            OneCExchangeOperation::query()
                ->whereKey($operation->id)
                ->update($updates);
        }
    }

    private function mapOperation(OneCExchangeOperation $operation): OneCExchangeDeliveryOperation
    {
        return new OneCExchangeDeliveryOperation(
            id: (int) $operation->id,
            organizationId: (int) $operation->organization_id,
            operationKey: (string) $operation->operation_key,
            correlationId: (string) $operation->correlation_id,
            direction: (string) $operation->direction,
            scope: (string) $operation->scope,
            entityType: $operation->entity_type ? (string) $operation->entity_type : null,
            entityId: $operation->entity_id ? (string) $operation->entity_id : null,
            idempotencyKey: $operation->idempotency_key ? (string) $operation->idempotency_key : null,
            status: (string) $operation->status,
            retryCount: (int) $operation->retry_count,
            maxAttempts: (int) $operation->max_attempts,
            failureType: $operation->failure_type ? (string) $operation->failure_type : null,
            accountingStatus: $operation->accounting_status ? (string) $operation->accounting_status : null,
            safePayloadPreview: is_array($operation->safe_payload_preview) ? $operation->safe_payload_preview : null,
            summary: is_array($operation->summary) ? $operation->summary : null,
            nextRetryAt: $this->dateTime($operation->next_retry_at),
        );
    }

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        return null;
    }
}
