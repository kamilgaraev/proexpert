<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Services\OneCExchange\Contracts\OneCExchangeClientInterface;
use App\Services\OneCExchange\Contracts\OneCExchangeOperationRepositoryInterface;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryAttempt;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryOperation;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryPayload;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryResult;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliverySummary;
use App\Services\OneCExchange\Support\OneCExchangePayloadSanitizer;
use App\Services\OneCExchange\Support\OneCExchangeRetryPolicy;
use DateTimeImmutable;
use Throwable;

final class OneCExchangeDeliveryOrchestrator
{
    private const STATUS_DELIVERED = 'delivered';
    private const STATUS_RETRY_SCHEDULED = 'retry_scheduled';
    private const STATUS_DEAD_LETTER = 'dead_letter';

    public function __construct(
        private readonly OneCExchangeOperationRepositoryInterface $repository,
        private readonly OneCExchangeClientInterface $client,
        private readonly OneCExchangeRetryPolicy $retryPolicy,
        private readonly OneCExchangePayloadSanitizer $sanitizer,
    ) {
    }

    public function run(int $limit = 50, ?DateTimeImmutable $now = null): OneCExchangeDeliverySummary
    {
        $now ??= new DateTimeImmutable();
        $limit = max(1, min($limit, 500));
        $processed = 0;
        $delivered = 0;
        $retryScheduled = 0;
        $deadLettered = 0;
        $failed = 0;

        for ($index = 0; $index < $limit; $index++) {
            $operation = $this->repository->claimNextDue($now);

            if ($operation === null) {
                break;
            }

            $processed++;
            $startedAt = microtime(true);
            $payload = $this->payloadFromOperation($operation);

            try {
                $result = $this->client->deliver($payload);
                $attempt = $this->attemptFromResult($operation, $payload, $result, $now, $startedAt);
            } catch (Throwable $exception) {
                $attempt = $this->attemptFromTransportException($operation, $payload, $exception, $now, $startedAt);
            }

            $this->repository->recordAttempt($operation, $attempt);

            match ($attempt->status) {
                self::STATUS_DELIVERED, 'accepted', 'posted', 'completed' => $delivered++,
                self::STATUS_RETRY_SCHEDULED => $retryScheduled++,
                self::STATUS_DEAD_LETTER => $deadLettered++,
                default => $failed++,
            };
        }

        return new OneCExchangeDeliverySummary(
            processed: $processed,
            delivered: $delivered,
            retryScheduled: $retryScheduled,
            deadLettered: $deadLettered,
            failed: $failed,
        );
    }

    private function payloadFromOperation(OneCExchangeDeliveryOperation $operation): OneCExchangeDeliveryPayload
    {
        return new OneCExchangeDeliveryPayload(
            operationId: $operation->id,
            organizationId: $operation->organizationId,
            operationKey: $operation->operationKey,
            correlationId: $operation->correlationId,
            direction: $operation->direction,
            scope: $operation->scope,
            entityType: $operation->entityType,
            entityId: $operation->entityId,
            idempotencyKey: $operation->idempotencyKey,
            safePayloadPreview: $operation->safePayloadPreview ?? [],
        );
    }

    private function attemptFromResult(
        OneCExchangeDeliveryOperation $operation,
        OneCExchangeDeliveryPayload $payload,
        OneCExchangeDeliveryResult $result,
        DateTimeImmutable $now,
        float $startedAt,
    ): OneCExchangeDeliveryAttempt {
        if ($result->accepted) {
            $status = in_array($result->status, ['delivered', 'accepted', 'posted', 'completed'], true)
                ? $result->status
                : self::STATUS_DELIVERED;

            return new OneCExchangeDeliveryAttempt(
                status: $status,
                failureType: null,
                retryable: false,
                nextRetryAt: null,
                safeErrorCode: null,
                safeErrorMessage: null,
                transportStatus: $result->transportStatus,
                safeRequestPreview: $payload->toRequestArray(),
                safeResponsePreview: $this->safeResponsePreview($result),
                durationMs: $this->durationMs($startedAt),
                externalId: $result->externalId,
                accountingStatus: $result->status,
            );
        }

        return $this->failureAttempt(
            operation: $operation,
            payload: $payload,
            retryable: $result->retryable,
            failureType: $result->failureType ?? $result->safeErrorCode ?? 'exchange_failed',
            safeErrorCode: $result->safeErrorCode ?? 'exchange_failed',
            safeErrorMessage: $result->safeErrorMessage ?? 'Не удалось выполнить обмен с 1C.',
            transportStatus: $result->transportStatus,
            response: $this->safeResponsePreview($result),
            now: $now,
            startedAt: $startedAt,
        );
    }

    private function attemptFromTransportException(
        OneCExchangeDeliveryOperation $operation,
        OneCExchangeDeliveryPayload $payload,
        Throwable $exception,
        DateTimeImmutable $now,
        float $startedAt,
    ): OneCExchangeDeliveryAttempt {
        return $this->failureAttempt(
            operation: $operation,
            payload: $payload,
            retryable: true,
            failureType: 'transport_error',
            safeErrorCode: 'transport_error',
            safeErrorMessage: $exception->getMessage(),
            transportStatus: null,
            response: [
                'status' => 'failed',
                'safe_error_code' => 'transport_error',
            ],
            now: $now,
            startedAt: $startedAt,
        );
    }

    private function failureAttempt(
        OneCExchangeDeliveryOperation $operation,
        OneCExchangeDeliveryPayload $payload,
        bool $retryable,
        string $failureType,
        string $safeErrorCode,
        string $safeErrorMessage,
        ?int $transportStatus,
        array $response,
        DateTimeImmutable $now,
        float $startedAt,
    ): OneCExchangeDeliveryAttempt {
        $decision = $retryable
            ? $this->retryPolicy->decide(
                status: 'failed',
                failureType: $failureType,
                attemptNumber: $operation->retryCount + 1,
                maxAttempts: $operation->maxAttempts,
                accountingStatus: $operation->accountingStatus,
                sourceIsActual: $operation->sourceIsActual(),
                now: $now,
            )
            : null;
        $safeError = $this->sanitizer->safeError($safeErrorMessage, $safeErrorCode);

        return new OneCExchangeDeliveryAttempt(
            status: $decision?->retryable ? self::STATUS_RETRY_SCHEDULED : self::STATUS_DEAD_LETTER,
            failureType: $failureType,
            retryable: (bool) $decision?->retryable,
            nextRetryAt: $decision?->retryable ? $decision->nextRetryAt : null,
            safeErrorCode: $safeError['code'],
            safeErrorMessage: $safeError['message'],
            transportStatus: $transportStatus,
            safeRequestPreview: $payload->toRequestArray(),
            safeResponsePreview: $response,
            durationMs: $this->durationMs($startedAt),
            externalId: null,
            accountingStatus: null,
        );
    }

    private function safeResponsePreview(OneCExchangeDeliveryResult $result): array
    {
        $response = $result->rawResponse ?? [
            'status' => $result->status,
            'external_id' => $result->externalId,
            'safe_error_code' => $result->safeErrorCode,
            'safe_error_message' => $result->safeErrorMessage,
        ];

        return $this->sanitizer->preview($response);
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
