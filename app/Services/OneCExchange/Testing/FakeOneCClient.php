<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Testing;

final readonly class FakeOneCClient
{
    private function __construct(private string $scenario)
    {
    }

    public static function withScenario(string $scenario): self
    {
        return new self($scenario);
    }

    public function sendDocument(OneCDocumentExchangePayload $document): OneCDocumentExchangeResult
    {
        return match ($this->scenario) {
            'happy_path' => new OneCDocumentExchangeResult(
                accepted: true,
                syncStatus: 'accepted',
                idempotencyKey: $document->idempotencyKey,
                externalId: '1c-' . str_replace('_', '-', $document->entityType) . '-' . $document->entityId,
                safeErrorCode: null,
                safeErrorMessage: null,
                retryable: false,
            ),
            'missing_mapping' => new OneCDocumentExchangeResult(
                accepted: false,
                syncStatus: 'requires_mapping',
                idempotencyKey: $document->idempotencyKey,
                externalId: null,
                safeErrorCode: 'mapping_missing',
                safeErrorMessage: 'Не найдено сопоставление для учетной системы.',
                retryable: false,
            ),
            'timeout' => new OneCDocumentExchangeResult(
                accepted: false,
                syncStatus: 'failed',
                idempotencyKey: $document->idempotencyKey,
                externalId: null,
                safeErrorCode: 'timeout',
                safeErrorMessage: 'Учетная система не ответила за отведенное время.',
                retryable: true,
            ),
            'business_rejection' => new OneCDocumentExchangeResult(
                accepted: false,
                syncStatus: 'rejected',
                idempotencyKey: $document->idempotencyKey,
                externalId: null,
                safeErrorCode: 'business_validation',
                safeErrorMessage: 'Учетная система отклонила документ по бизнес-правилу.',
                retryable: false,
            ),
            default => new OneCDocumentExchangeResult(
                accepted: false,
                syncStatus: 'failed',
                idempotencyKey: $document->idempotencyKey,
                externalId: null,
                safeErrorCode: 'server_error',
                safeErrorMessage: 'Учетная система временно недоступна.',
                retryable: true,
            ),
        };
    }
}
