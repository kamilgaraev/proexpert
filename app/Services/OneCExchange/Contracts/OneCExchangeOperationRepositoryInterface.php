<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Contracts;

use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryAttempt;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryOperation;
use DateTimeImmutable;

interface OneCExchangeOperationRepositoryInterface
{
    public function claimNextDue(DateTimeImmutable $now): ?OneCExchangeDeliveryOperation;

    public function recordAttempt(OneCExchangeDeliveryOperation $operation, OneCExchangeDeliveryAttempt $attempt): void;
}
