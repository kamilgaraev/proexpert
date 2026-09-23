<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use RuntimeException;

final class PushDeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $unregisteredToken = false)
    {
        parent::__construct($message);
    }
}
