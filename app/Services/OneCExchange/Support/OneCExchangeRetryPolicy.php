<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Support;

use DateTimeImmutable;

final class OneCExchangeRetryPolicy
{
    private const BACKOFF_SECONDS = [
        1 => 60,
        2 => 300,
        3 => 900,
        4 => 3600,
        5 => 10800,
    ];

    private const RETRYABLE_FAILURE_TYPES = [
        'timeout',
        'server_error',
        'transport_error',
        'network_error',
        'rate_limit',
        'unavailable',
        'partial_success',
    ];

    private const FINAL_ACCOUNTING_STATUSES = [
        'posted',
        'accounted',
    ];

    public function delaySecondsForAttempt(int $attemptNumber): int
    {
        return self::BACKOFF_SECONDS[$attemptNumber] ?? self::BACKOFF_SECONDS[array_key_last(self::BACKOFF_SECONDS)];
    }

    public function decide(
        string $status,
        ?string $failureType,
        int $attemptNumber,
        int $maxAttempts,
        ?string $accountingStatus,
        bool $sourceIsActual,
        DateTimeImmutable $now
    ): OneCExchangeRetryDecision {
        if ($status === 'completed' || $status === 'accepted' || $status === 'posted') {
            return new OneCExchangeRetryDecision(false, false, null, 'Операция уже завершена.');
        }

        if ($accountingStatus !== null && in_array($accountingStatus, self::FINAL_ACCOUNTING_STATUSES, true)) {
            return new OneCExchangeRetryDecision(false, false, null, 'Документ уже подтвержден учетной системой.');
        }

        if (!$sourceIsActual) {
            return new OneCExchangeRetryDecision(false, false, null, 'Источник данных изменился после отправки.');
        }

        if ($failureType === null || !in_array($failureType, self::RETRYABLE_FAILURE_TYPES, true)) {
            return new OneCExchangeRetryDecision(false, false, null, 'Ошибка требует ручной проверки.');
        }

        if ($attemptNumber >= $maxAttempts) {
            return new OneCExchangeRetryDecision(false, true, null, 'Превышен лимит повторных доставок.');
        }

        $nextAttemptNumber = $attemptNumber + 1;
        $nextRetryAt = $now->modify('+' . $this->delaySecondsForAttempt($nextAttemptNumber) . ' seconds');

        return new OneCExchangeRetryDecision(true, false, $nextRetryAt, 'Повторная доставка разрешена.');
    }
}
