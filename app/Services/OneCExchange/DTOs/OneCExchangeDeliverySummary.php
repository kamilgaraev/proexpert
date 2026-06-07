<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\DTOs;

final readonly class OneCExchangeDeliverySummary
{
    public function __construct(
        public int $processed,
        public int $delivered,
        public int $retryScheduled,
        public int $deadLettered,
        public int $failed,
    ) {
    }
}
