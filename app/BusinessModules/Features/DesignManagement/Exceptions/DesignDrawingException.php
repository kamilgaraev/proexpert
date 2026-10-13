<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Exceptions;

use DomainException;

final class DesignDrawingException extends DomainException
{
    public function __construct(public readonly string $messageKey, int $status = 422)
    {
        parent::__construct(trans_message($messageKey), $status);
    }
}
