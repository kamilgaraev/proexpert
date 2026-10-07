<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway\Contracts;

interface GatewayModelTransport
{
    public function send(GatewayModelRequest $request): GatewayModelResponse;
}
