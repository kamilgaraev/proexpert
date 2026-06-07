<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Support;

use DateTimeImmutable;

final readonly class OneCExchangeRetryDecision
{
    public function __construct(
        public bool $retryable,
        public bool $deadLetter,
        public ?DateTimeImmutable $nextRetryAt,
        public string $reason
    ) {
    }
}
