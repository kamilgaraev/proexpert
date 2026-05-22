<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Exceptions;

use DomainException;

final class WorkforceAttendanceException extends DomainException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $statusCode = 422
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
