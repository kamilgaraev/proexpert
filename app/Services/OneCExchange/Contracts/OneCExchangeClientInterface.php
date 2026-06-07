<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Contracts;

use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryPayload;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryResult;

interface OneCExchangeClientInterface
{
    public function deliver(OneCExchangeDeliveryPayload $payload): OneCExchangeDeliveryResult;
}
